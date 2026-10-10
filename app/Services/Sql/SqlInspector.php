<?php

namespace App\Services\Sql;

use App\Enums\DbDriver;
use App\Enums\StatementType;
use InvalidArgumentException;
use OverflowException;
use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\DeleteStatement;
use PhpMyAdmin\SqlParser\Statements\ExplainStatement;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Statements\ShowStatement;
use PhpMyAdmin\SqlParser\Statements\UpdateStatement;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;
use PhpMyAdmin\SqlParser\TokenType;

/**
 * Parses submitted SQL, enforces the QueryProxy guard rules and produces the
 * prepared SQL that will actually be executed.
 *
 * Guard rules (PRD 3.2):
 *  - UPDATE / DELETE without WHERE are rejected.
 *  - SELECT without LIMIT gets the default limit injected; LIMITs above the
 *    hard cap are clamped. The executor additionally enforces the hard cap on
 *    every read cursor, so the cap holds even for dialects this guard cannot
 *    rewrite.
 *  - Multiple statements are only allowed inside an explicit
 *    BEGIN; ...; COMMIT; transaction block.
 *  - A set of administrative / file-IO statements is always rejected.
 *
 * All pattern checks run against a normalized form of the statement (comments
 * stripped, whitespace collapsed, string literals — dollar-quoted bodies
 * included — blanked) so that comment tricks like "DROP/**\/DATABASE" cannot
 * smuggle a forbidden statement past the anchored patterns. Executable
 * comments are not comments to the server and are not stripped: the body of
 * a MySQL "/*!...*\/" comment, and on MariaDB (or an unknown driver) of a
 * MariaDB "/*M!...*\/" comment, is inspected as the SQL it is, whatever
 * version condition it carries.
 *
 * Three statements are not judged by their syntax but by the dangerous part
 * inside them, because the syntax itself has ordinary DBA uses that the guard
 * must not take away:
 *
 *  - CREATE EXTENSION is judged by the extension name. Untrusted procedural
 *    languages (plpythonu, plperlu, ...) run as the database superuser by
 *    definition and are rejected, whether they are named in the list or only
 *    by PostgreSQL's "pl...u" convention for an untrusted language;
 *    pg_stat_statements, pgcrypto, uuid-ossp, postgis and every other
 *    extension pass.
 *  - DO is judged by its LANGUAGE clause and by its body. An explicitly
 *    untrusted language is rejected; no clause means the PostgreSQL default
 *    plpgsql, which passes, as does an explicit trusted language. The body is
 *    then scanned with the unconditional denylist, so a block cannot carry a
 *    DROP DATABASE or a GRANT the guard refuses on its own.
 *  - SET is judged by the variable name. Variables that name a file path,
 *    load code or switch a protection off are rejected; max_connections,
 *    wait_timeout, work_mem and the rest pass. On MySQL / MariaDB
 *    only the persistent scopes are guarded (GLOBAL / PERSIST / PERSIST_ONLY,
 *    in both the keyword and the "@@scope." spelling) and SESSION-scoped
 *    writes are not touched — except for sql_mode, which is refused in every
 *    scope and spelling ({@see self::LEXER_MODE_VARIABLES}). PostgreSQL has
 *    no persistent SET scope — a session setting is the whole door — so on
 *    PostgreSQL, and when the driver is unknown, a listed name is rejected
 *    in every scope: plain SET, SET SESSION and SET LOCAL alike.
 *
 * Those two lists live in config/queryproxy.php and can only be extended from
 * the environment, never shortened. {@see self::UNTRUSTED_LANGUAGES} and
 * {@see self::DANGEROUS_VARIABLES} are the floor the configuration is merged
 * on top of, so a missing or cached-stale config cannot silently disarm them.
 *
 * The forbidden-statement denylist is structurally incomplete and always will
 * be. It enumerates dangerous syntax that is known today across several
 * dialects; a spelling it does not name yet — a vendor extension, a function
 * alias, a future server feature — passes it. Treat it as one layer of
 * defence in depth, never as the authorization boundary: the DBA approval
 * workflow is what actually authorizes a statement, and the denylist only
 * removes the most obviously abusive requests before they ever reach a human.
 *
 * That is doubly true of the DO body scan. It reads SQL that was written out
 * in plain text; it cannot read SQL the body computes at run time, so
 * EXECUTE format('DR'||'OP DATABASE prod') and EXECUTE a_variable pass it, and
 * no static scan of a procedural body can do otherwise. A DO block running
 * trusted plpgsql is still arbitrary server-side code. What the rules buy is
 * the removal of the direct superuser-code-execution paths and of the plainly
 * written forbidden statement, not a bound on what an approved procedural
 * block may do.
 *
 * Some statements are not what their first keyword says they are, and are
 * classified by what they actually do:
 *
 *  - EXPLAIN ANALYZE (and the PostgreSQL option-list spelling
 *    EXPLAIN (ANALYZE ...)) executes the statement it explains, so that inner
 *    statement is inspected in its own right and lends the outer one its
 *    type, its WHERE rule and its violations. An option block the guard
 *    cannot read — an unknown, quoted or escaped option name, a value
 *    PostgreSQL would not take as a boolean — is rejected rather than waved
 *    through as a read. A plain EXPLAIN only plans on PostgreSQL and SQLite,
 *    so there the calls it names are not judged.
 *  - MariaDB's ANALYZE [FORMAT = x] <statement> runs its statement too and
 *    is judged like EXPLAIN ANALYZE; it may only run SELECT, INSERT,
 *    REPLACE, UPDATE or DELETE. The mysql and the unknown driver are read
 *    the same way, since either may front a MariaDB server. ANALYZE TABLE
 *    and the PostgreSQL / SQLite ANALYZE keep their classification.
 *  - SELECT ... INTO <table> creates a table (PostgreSQL, SQL Server) and is
 *    a DDL write; MySQL's SELECT ... INTO @variable stays a read.
 *  - The leading keyword, INTO and every called function name are read
 *    from the token stream, so spacing ("EXPLAIN(ANALYZE)DELETE",
 *    "SELECT*INTO t2"), wrapping parentheses and quoted or U&"..."-escaped
 *    names do not change the verdict.
 *  - A function call can control the server, reach another server or change
 *    state: the first two kinds are rejected, set_config() is judged like
 *    SET, and the rest turn the statement into a write. The function lists
 *    are floors that configuration can only extend, like the two above.
 *
 * The parser is MySQL-dialect-first; statements it cannot fully parse are
 * guarded best-effort by keyword heuristics and classified as writes unless
 * they clearly read.
 */
class SqlInspector
{
    private const READ_KEYWORDS = ['SELECT', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC'];

    private const DDL_KEYWORDS = ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'];

    /**
     * Statements that may wrap another statement in an EXPLAIN (MySQL treats
     * DESCRIBE and DESC as synonyms of EXPLAIN).
     */
    private const EXPLAIN_KEYWORDS = ['EXPLAIN', 'DESCRIBE', 'DESC'];

    /**
     * The statements MariaDB's ANALYZE <statement> may run; "(" stands for a
     * parenthesised SELECT. Any other statement behind ANALYZE is rejected.
     */
    private const ANALYZE_STATEMENT_KEYWORDS = ['SELECT', 'WITH', '(', 'INSERT', 'REPLACE', 'UPDATE', 'DELETE'];

    /**
     * The options a PostgreSQL EXPLAIN (...) list may name. Any other option
     * name — unknown, quoted or escaped — makes the option list unreadable,
     * and the statement is rejected rather than assumed not to run.
     */
    private const EXPLAIN_OPTIONS = [
        'ANALYZE', 'ANALYSE', 'VERBOSE', 'COSTS', 'SETTINGS', 'GENERIC_PLAN', 'BUFFERS',
        'SERIALIZE', 'WAL', 'TIMING', 'SUMMARY', 'MEMORY', 'FORMAT',
    ];

    /**
     * Server-side file IO functions, matched on the decoded call name so that
     * a quoted or U&-escaped spelling is caught as well as the plain one the
     * denylist pattern sees.
     */
    private const FILE_IO_FUNCTIONS = [
        'pg_read_file', 'pg_read_binary_file', 'pg_ls_dir', 'lo_import', 'lo_export', 'load_file',
    ];

    /**
     * How deep one statement may wrap another (EXPLAIN ANALYZE EXPLAIN ...)
     * before the guard stops reading and rejects it.
     */
    private const MAX_NESTING_DEPTH = 3;

    /**
     * How deep MariaDB executable comments ("/*M!...*\/") may sit inside one
     * another before the guard stops exposing them and rejects the SQL.
     */
    private const MAX_EXECUTABLE_COMMENT_DEPTH = 3;

    /**
     * How deep dollar-quoted strings may sit inside one another in the body
     * of a DO block before the body scan stops reading and rejects it.
     */
    private const MAX_BLOCK_BODY_QUOTE_DEPTH = 8;

    /**
     * What the guard reports for a version-conditional comment whose end it
     * cannot place where the server does (see versionConditionalComments()).
     */
    private const VERSIONED_COMMENT_BOUNDARY_VIOLATION = 'A version-conditional comment ("/*!NNNNN ... */") nested in or holding another comment, or with "*/" inside a string or line comment in its body, is not allowed: the server, which may skip its body as raw text, would end it at another place than QueryProxy reads.';

    /**
     * What SQL Server's batch reader reports for BEGIN TRAN, COMMIT, ROLLBACK
     * or SAVE TRAN inside a batch.
     */
    private const SQLSRV_TRANSACTION_CONTROL_VIOLATION = 'Transaction control (BEGIN TRAN, COMMIT, ROLLBACK, SAVE TRAN) inside a SQL Server batch is not allowed: QueryProxy runs the request in its own transaction.';

    /**
     * SQL Server procedures that run, schedule or send a statement passed to
     * them as text, in a form the guard does not read: prepared and cursor
     * handles, the per-table / per-database loops, the legacy sp_sqlexec, a
     * mail with a query attached and a SQL Agent job step. Invoking one is
     * rejected. sp_executesql is not listed: its statement argument is read
     * and inspected (see dynamicSqlViolations()).
     *
     * This list is an exception to D-13 (b), which keeps "EXEC procedure"
     * a Write: by user decision (2026-10-02, phase 03 review round 1) every
     * procedure here is refused regardless of its arguments — including
     * sp_send_dbmail and the job step procedures called without a query or
     * command — because the SQL they can run is not visible to the guard.
     */
    private const DYNAMIC_SQL_PROCEDURES = [
        'sp_prepare',
        'sp_prepexec',
        'sp_prepexecrpc',
        'sp_execute',
        'sp_cursoropen',
        'sp_cursorprepare',
        'sp_cursorprepexec',
        'sp_msforeachtable',
        'sp_msforeachdb',
        'sp_msforeach_worker',
        'sp_sqlexec',
        'sp_send_dbmail',
        'sp_add_jobstep',
        'sp_update_jobstep',
    ];

    /**
     * Stands in for a SQL Server "#" (temporary table marker) while the
     * statement is inspected; see maskTemporaryTableMarkers().
     */
    private const TEMPORARY_TABLE_SENTINEL = "\x7F";

    /**
     * Procedural languages whose functions execute outside the database's
     * permission system — installing one, or asking a DO block to run in one,
     * is equivalent to shell access on the database host. The names below are
     * the known ones; isUntrustedLanguage() additionally refuses anything
     * shaped like PostgreSQL's "pl...u" untrusted-language convention, so a
     * language nobody has listed yet is still caught.
     *
     * Configuration is merged on top of this list and can only extend it;
     * these entries are the floor.
     *
     * @var list<string>
     */
    public const UNTRUSTED_LANGUAGES = [
        'plpythonu',
        'plpython2u',
        'plpython3u',
        'plperlu',
        'pltclu',
        'plsh',
        'plr',
        'plluau',
        'plrubyu',
        'plphpu',
        'pljavau',
    ];

    /**
     * Server settings whose value names a file path, loads code into the
     * server process, or switches a protection off. Matching is on the exact
     * name, case-insensitively: "general_log" and "general_log_file" are two
     * separate doors and both are listed.
     *
     * The list carries the names of both dialects, and the connection's
     * driver — not the dialect a name belongs to — decides how SET is judged:
     * on MySQL / MariaDB a listed name is refused only in the persistent
     * scopes (GLOBAL / PERSIST / PERSIST_ONLY); on PostgreSQL, and when the
     * driver is unknown, it is refused in every scope (plain SET, SET SESSION,
     * SET LOCAL). A literal name passed to set_config() is refused on every
     * driver. The PostgreSQL entries are the settings a session can change
     * with SET — as an ordinary user or as a superuser — that load a shared
     * library (session_preload_libraries, local_preload_libraries,
     * dynamic_library_path), lift a read-only guard
     * (default_transaction_read_only, transaction_read_only), stop triggers
     * and foreign keys from firing (session_replication_role), or switch off
     * a catalog, large-object or page-integrity check
     * (allow_system_table_mods, lo_compat_privileges, zero_damaged_pages,
     * ignore_checksum_failure). Settings only a reload or a restart can
     * change are left out: no SET can reach them.
     *
     * sql_mode is listed for a different reason: NO_BACKSLASH_ESCAPES and
     * ANSI_QUOTES change how the server reads string literals, and the guard
     * reads SQL with the default mode. It is refused in every scope on every
     * driver ({@see self::LEXER_MODE_VARIABLES}); QueryExecutor refuses to run
     * on a MySQL / MariaDB session whose mode already carries either flag.
     *
     * Configuration is merged on top of this list and can only extend it;
     * these entries are the floor.
     *
     * @var list<string>
     */
    public const DANGEROUS_VARIABLES = [
        // MySQL / MariaDB
        'general_log',
        'general_log_file',
        'slow_query_log',
        'slow_query_log_file',
        'log_error',
        'init_file',
        'init_connect',
        'plugin_dir',
        'plugin_load',
        'plugin_load_add',
        'secure_file_priv',
        'local_infile',
        'sql_mode',
        // PostgreSQL
        'session_preload_libraries',
        'local_preload_libraries',
        'dynamic_library_path',
        'default_transaction_read_only',
        'transaction_read_only',
        'session_replication_role',
        'allow_system_table_mods',
        'lo_compat_privileges',
        'zero_damaged_pages',
        'ignore_checksum_failure',
    ];

    /**
     * Functions that control the server or other sessions, open a channel
     * to another server, or run SQL handed to them as text (query_to_xml*,
     * ts_stat, ts_rewrite's query form, and the tablefunc / xml2 contrib
     * functions crosstab*, connectby and xpath_table, which build and run a
     * query from their text arguments), which no scan of the calling
     * statement can see. Calling one
     * is rejected outright. A trailing "*" matches every name with that
     * prefix.
     *
     * Configuration is merged on top of this list and can only extend it;
     * these entries are the floor.
     *
     * @var list<string>
     */
    public const BLOCKED_FUNCTIONS = [
        'pg_terminate_backend',
        'pg_cancel_backend',
        'pg_reload_conf',
        'pg_rotate_logfile',
        'pg_promote',
        'pg_switch_wal',
        'dblink*',
        'query_to_xml',
        'query_to_xmlschema',
        'query_to_xml_and_xmlschema',
        'ts_stat',
        'ts_rewrite',
        'crosstab*',
        'connectby',
        'xpath_table',
    ];

    /**
     * Functions that change database state — sequences and locks — while
     * looking like a read. Calling one makes the statement a write.
     *
     * Configuration is merged on top of this list and can only extend it;
     * these entries are the floor.
     *
     * @var list<string>
     */
    public const STATE_CHANGING_FUNCTIONS = [
        'nextval',
        'setval',
        'pg_advisory_lock*',
        'pg_advisory_xact_lock*',
        'pg_try_advisory_lock*',
        'pg_try_advisory_xact_lock*',
        'pg_advisory_unlock*',
        'get_lock',
        'release_lock',
    ];

    /**
     * Functions whose only effect is to hold a connection or burn CPU. Calling
     * one makes the statement a write, so it is not presented to the approver
     * as a harmless read.
     *
     * Configuration is merged on top of this list and can only extend it;
     * these entries are the floor.
     *
     * @var list<string>
     */
    public const RESOURCE_CONSUMING_FUNCTIONS = [
        'pg_sleep',
        'pg_sleep_for',
        'pg_sleep_until',
        'sleep',
        'benchmark',
    ];

    /**
     * SQL Server procedures that run operating-system commands, change the
     * server configuration, drive OLE automation objects or register another
     * server, register a DLL or run an external R / Python script. Invoking
     * one — as the first word of a statement or after EXEC / EXECUTE,
     * however the name is qualified or bracketed — is rejected. A trailing
     * "*" matches every name with that prefix.
     *
     * Only the invocation is judged, not every mention: a column that happens
     * to be called xp_points is not a procedure call.
     *
     * Configuration is merged on top of this list and can only extend it;
     * these entries are the floor.
     *
     * @var list<string>
     */
    public const BLOCKED_PROCEDURES = [
        'xp_*',
        'sp_configure',
        'sp_oa*',
        'sp_addlinkedserver',
        'sp_addlinkedsrvlogin',
        'sp_serveroption',
        'sp_addextendedproc',
        'sp_execute_external_script',
    ];

    /**
     * SQL Server rowset functions that read from another server or from a
     * file on the database host. Calling one is rejected.
     */
    private const REMOTE_ROWSET_FUNCTIONS = ['openrowset', 'opendatasource', 'openquery'];

    /**
     * Functions that load native code into the database process.
     */
    private const CODE_LOADING_FUNCTIONS = ['load_extension'];

    /**
     * SQLite pragmas that only report on the schema or the build. Any other
     * pragma — and any pragma written with a value — is rejected: pragmas
     * such as writable_schema or journal_mode change the database file or
     * switch a protection off.
     *
     * Every entry here takes, at most, a read argument (the table or index
     * to report on). Configuration is merged on top of this list and can
     * only extend it; a configured pragma is allowed only in its bare read
     * form ("PRAGMA user_version"), because "PRAGMA x(v)" assigns a value
     * just like "PRAGMA x = v". These entries are the floor.
     *
     * @var list<string>
     */
    public const SQLITE_ALLOWED_PRAGMAS = [
        'table_info',
        'table_xinfo',
        'index_list',
        'index_info',
        'index_xinfo',
        'foreign_key_list',
        'database_list',
        'table_list',
        'collation_list',
        'function_list',
        'compile_options',
    ];

    /**
     * SET scopes that survive the session and therefore change the server for
     * everyone. SESSION / LOCAL writes are deliberately not guarded.
     *
     * @var list<string>
     */
    private const PERSISTENT_SCOPES = ['GLOBAL', 'PERSIST', 'PERSIST_ONLY'];

    /**
     * Settings that change how the server tokenizes SQL — which quotes delimit
     * a string, whether a backslash escapes — and so would make the guard,
     * which reads SQL with the server defaults, see different statements than
     * the server runs. A write to one of them is refused in every scope on
     * every driver, SESSION included. Each entry is also listed in
     * {@see self::DANGEROUS_VARIABLES}.
     *
     * @var list<string>
     */
    private const LEXER_MODE_VARIABLES = ['sql_mode'];

    private const INVALID_UTF8_VIOLATION = 'SQL must be valid UTF-8.';

    private const UNREADABLE_SQL_VIOLATION = 'QueryProxy could not read this SQL: the lexer found no statement in text that is not empty.';

    private const UNICODE_WHITESPACE_VIOLATION = 'SQL must not contain Unicode whitespace or byte-order marks outside string literals, quoted identifiers and comments.';

    /**
     * Characters the lexer reads as part of a name (never as whitespace)
     * while a server may treat them as a separator: the byte-order mark /
     * zero-width no-break space and the Unicode space and line / paragraph
     * separators. "\u{FEFF}DROP DATABASE prod" is a single unknown word to
     * the lexer, so the guard sees no DROP at all.
     */
    private const UNICODE_WHITESPACE_PATTERN = '/[\x{FEFF}\x{0085}\x{00A0}\x{1680}\x{180E}\x{2000}-\x{200B}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]/u';

    private const FORBIDDEN_PATTERNS = [
        '/^DROP\s+(DATABASE|SCHEMA)\b/i' => 'DROP DATABASE is not allowed through QueryProxy.',
        '/^GRANT\b/i' => 'GRANT statements are not allowed through QueryProxy.',
        '/^REVOKE\b/i' => 'REVOKE statements are not allowed through QueryProxy.',
        '/^DENY\b/i' => 'DENY statements are not allowed through QueryProxy.',
        '/^(CREATE|ALTER|DROP)\s+(USER|ROLE|LOGIN)\b/i' => 'User / role management statements are not allowed through QueryProxy.',
        '/^CREATE\s+(AGGREGATE\s+)?FUNCTION\b.*\bSONAME\b/i' => 'CREATE FUNCTION ... SONAME is not allowed through QueryProxy (loadable UDF).',
        '/^SHUTDOWN\b/i' => 'SHUTDOWN is not allowed through QueryProxy.',
        '/^LOAD\s+DATA\b/i' => 'LOAD DATA is not allowed through QueryProxy.',
        '/^COPY\b/i' => 'COPY is not allowed through QueryProxy (server-side file / program IO).',
        '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i' => 'SELECT ... INTO OUTFILE / DUMPFILE is not allowed through QueryProxy.',
        '/\bLOAD_FILE\s*\(/i' => 'LOAD_FILE() is not allowed through QueryProxy.',
        // Call form only: literals are already blanked by normalize(), and an
        // identifier that merely *looks* like one of these is not a call.
        '/\b(PG_READ_FILE|PG_READ_BINARY_FILE|PG_LS_DIR|LO_IMPORT|LO_EXPORT)\s*\(/i' => 'Server-side file IO functions are not allowed through QueryProxy.',
    ];

    public function inspect(string $sql, ?DbDriver $driver = null): InspectionResult
    {
        $this->inspectDepth++;

        try {
            return $this->inspectRequest($sql, $driver);
        } finally {
            if (--$this->inspectDepth === 0) {
                $this->lexedTokens = [];
                $this->byteOffsetsSql = null;
                $this->byteOffsets = null;
            }
        }
    }

    private function inspectRequest(string $sql, ?DbDriver $driver): InspectionResult
    {
        [$sql, $lexicalViolation] = $this->lexicalView($sql, $driver);

        if ($lexicalViolation !== null) {
            return new InspectionResult([], false, [$this->unmaskTemporaryTableMarkers($lexicalViolation)]);
        }

        [$skipped, $versionViolation] = $this->skippedCommentReading($sql, $driver);

        if ($versionViolation !== null) {
            return new InspectionResult([], false, [$versionViolation]);
        }

        $executed = $this->inspectView($sql, $driver);

        if ($skipped === null || ! $executed->passes() || $this->splitStatements($skipped) === []) {
            return $executed;
        }

        return $this->reconcileSkippedReading($executed, $this->inspectView($skipped, $driver), $driver);
    }

    /**
     * Split, transaction-check and judge SQL that has already been through
     * lexicalView(): one reading of the request as the server may run it.
     */
    private function inspectView(string $sql, ?DbDriver $driver): InspectionResult
    {
        $violations = [];
        $rawStatements = $this->splitStatements($sql);

        if ($rawStatements === []) {
            return new InspectionResult([], false, ['No executable SQL statement found.']);
        }

        [$executables, $isTransaction, $transactionViolations] = $this->extractTransaction($rawStatements);
        $violations = array_merge($violations, $transactionViolations);

        if (count($executables) > 1 && ! $isTransaction) {
            $violations[] = 'Multiple statements must be wrapped in an explicit transaction (BEGIN; ...; COMMIT;).';
        }

        if ($executables === [] && $violations === []) {
            $violations[] = 'The transaction block contains no executable statements.';
        }

        $infos = [];

        foreach ($executables as $index => $statementSql) {
            [$info, $statementViolations] = $driver === DbDriver::Sqlsrv
                ? $this->analyzeSqlsrvBatch($statementSql, $index + 1)
                : $this->analyzeStatement($statementSql, $index + 1, $driver);
            $infos[] = $info;
            $violations = array_merge($violations, $statementViolations);
        }

        if ($driver === DbDriver::Sqlsrv) {
            $infos = array_map(fn (StatementInfo $info): StatementInfo => new StatementInfo(
                $this->unmaskTemporaryTableMarkers($info->sql),
                $this->unmaskTemporaryTableMarkers($info->preparedSql),
                $info->type,
                $info->parsed,
                $info->limitInjected,
                $info->limitClamped,
                $info->isDdl,
            ), $infos);
            $violations = array_map($this->unmaskTemporaryTableMarkers(...), $violations);
        }

        return new InspectionResult($infos, $isTransaction, array_values(array_unique($violations)));
    }

    /**
     * The lexical checks every piece of SQL text goes through before it is
     * split and judged — the top-level request and every statement held in
     * a string that a dynamic-SQL command runs: comments resolved the way
     * the server reads them, U& escapes, dollar quoting and the SQL Server /
     * SQLite quoting rules. Returns the text the guard reads (comments
     * resolved, SQL Server "#" masked) and the first lexical violation.
     *
     * @return array{0: string, 1: ?string}
     */
    private function lexicalView(string $sql, ?DbDriver $driver): array
    {
        // Checked on the text as sent: resolving the comments could drop the
        // bytes the lexer cannot read, and the server would still get them.
        if (! mb_check_encoding($sql, 'UTF-8')) {
            return [$sql, self::INVALID_UTF8_VIOLATION];
        }

        [$sql, $lexicalViolation] = $this->resolveComments($sql, $driver);
        $lexicalViolation ??= $this->unicodeEscapeClauseViolation($sql, $driver);
        $lexicalViolation ??= $this->dollarQuoteDialectViolation($sql, $driver);
        $lexicalViolation ??= $this->postgresLexicalViolation($sql, $driver);
        $dialectRanges = null;

        if ($lexicalViolation === null && ($driver === DbDriver::Sqlsrv || $driver === DbDriver::Sqlite)) {
            [$sql, $dialectRanges, $lexicalViolation] = $this->maskTemporaryTableMarkers($sql, $driver);
        }

        $lexicalViolation ??= $this->dialectCommentViolation($sql, $driver);

        if ($lexicalViolation === null && $dialectRanges !== null && $driver !== null) {
            $lexicalViolation = $this->dialectLexicalViolation($sql, $dialectRanges, $driver);
        }

        if ($lexicalViolation === null) {
            $tokens = $this->lexTokens($sql);
            $lexicalViolation = $this->unicodeWhitespaceViolation($sql, $driver, $dialectRanges, $tokens)
                ?? $this->unreadableTextViolation($sql, $tokens);
        }

        return [$sql, $lexicalViolation];
    }

    /**
     * Why the lexer cannot read $sql, or null when it can. Fail-closed: the
     * guard judges only the tokens the lexer produces, so text it cannot
     * tokenize must never look like an empty request.
     *
     *  - The text is not valid UTF-8. The lexer stops at such a byte and
     *    returns no tokens at all, so "DROP DATABASE prod /* \xFF *\/" would
     *    otherwise read as nothing — while the server runs it.
     *  - The text is not blank or comment-only, yet the lexer produced no
     *    token beyond whitespace, comments and its closing delimiter.
     *
     * @param  list<Token>  $tokens  the lexer's tokens for $sql
     */
    private function unreadableTextViolation(string $sql, array $tokens): ?string
    {
        if (! mb_check_encoding($sql, 'UTF-8')) {
            return self::INVALID_UTF8_VIOLATION;
        }

        foreach ($tokens as $token) {
            $empty = $token->type === TokenType::Whitespace
                || $token->type === TokenType::Comment
                || ($token->type === TokenType::Delimiter && $token->token === '');

            if (! $empty) {
                return null;
            }
        }

        $trimmed = trim($sql);

        return $trimmed === '' || $this->isCommentOnly($trimmed) ? null : self::UNREADABLE_SQL_VIOLATION;
    }

    /**
     * Refuse a byte-order mark or Unicode whitespace character (see
     * UNICODE_WHITESPACE_PATTERN) anywhere outside a string literal, a quoted
     * identifier or a comment. Fail-closed: the lexer glues such a character
     * to the words around it, so a server that reads it as a separator would
     * run keywords the guard never saw. Inside a name it is refused too.
     *
     * On SQL Server and SQLite the strings, quoted and bracketed names and
     * comments are those the server's own rules locate ($dialectRanges,
     * character positions); elsewhere they are the lexer's string, quoted
     * symbol and comment tokens, plus the dollar-quoted strings on
     * PostgreSQL (and an unknown driver), which the lexer does not know. The
     * body of a MySQL executable comment is tokenized by the lexer, so it is
     * checked like any other text.
     *
     * Both the matches and the opaque ranges are walked once in order, so
     * the check stays linear in the length of the SQL however many matches
     * and ranges it holds.
     *
     * @param  ?array{quoted: list<array{0: int, 1: int}>, comments: list<array{0: int, 1: int}>, brackets: list<array{0: int, 1: int}>}  $dialectRanges
     * @param  array<int, Token>  $tokens  the lexer's tokens for $sql
     */
    private function unicodeWhitespaceViolation(string $sql, ?DbDriver $driver, ?array $dialectRanges, array $tokens): ?string
    {
        if (! preg_match_all(self::UNICODE_WHITESPACE_PATTERN, $sql, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $positions = array_column($matches[0], 1);

        if ($dialectRanges !== null) {
            // The dialect ranges count characters; the matches count bytes.
            // The offsets ascend, so each one is converted from the last.
            $byteOffset = 0;
            $charOffset = 0;

            foreach ($positions as $index => $position) {
                $charOffset += mb_strlen(substr($sql, $byteOffset, $position - $byteOffset), 'UTF-8');
                $byteOffset = $position;
                $positions[$index] = $charOffset;
            }

            $opaque = array_merge($dialectRanges['quoted'], $dialectRanges['comments'], $dialectRanges['brackets']);

            return $this->allWithinRanges($positions, $opaque) ? null : self::UNICODE_WHITESPACE_VIOLATION;
        }

        $opaque = $driver === null || $driver === DbDriver::Pgsql ? $this->dollarQuotedRanges($sql) : [];

        foreach ($this->tokensWithByteOffsets($sql, $tokens) as [$token, $offset]) {
            $text = (string) $token->token;
            $isQuotedName = $token->type === TokenType::Symbol
                && (str_starts_with($text, '`') || str_starts_with($text, '"'));

            if ($token->type === TokenType::String || $token->type === TokenType::Comment || $isQuotedName) {
                $opaque[] = [$offset, $offset + strlen($text)];
            }
        }

        return $this->allWithinRanges($positions, $opaque) ? null : self::UNICODE_WHITESPACE_VIOLATION;
    }

    /**
     * True when every position falls inside one of the half-open ranges.
     * The ranges may arrive unordered and may overlap; they are sorted once
     * and then walked alongside the ascending positions.
     *
     * @param  list<int>  $positions  ascending
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    private function allWithinRanges(array $positions, array $ranges): bool
    {
        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $count = count($ranges);
        $index = 0;
        $reach = PHP_INT_MIN;

        foreach ($positions as $position) {
            // $reach is the furthest end among the ranges starting at or
            // before $position, so overlapping ranges need no merge step.
            while ($index < $count && $ranges[$index][0] <= $position) {
                $reach = max($reach, $ranges[$index][1]);
                $index++;
            }

            if ($position >= $reach) {
                return false;
            }
        }

        return true;
    }

    /**
     * Split raw SQL into individual statement strings on top-level delimiters,
     * dropping empty / comment-only segments.
     *
     * The lexer is MySQL-dialect-first and does not know PostgreSQL's
     * dollar quoting, so it reports every ";" inside a "$$ ... $$" body as a
     * statement delimiter and shreds a perfectly ordinary plpgsql DO block
     * into fragments. Delimiters that fall inside a dollar-quoted region are
     * therefore skipped: in PostgreSQL that region is a string literal, and a
     * semicolon inside a literal has never ended a statement.
     *
     * Text the lexer cannot read (see unreadableTextViolation()) is never
     * split: reading it as no statement at all would be fail-open.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException when the lexer cannot read $sql
     */
    public function splitStatements(string $sql): array
    {
        $tokens = $this->lexTokens($sql);
        $unreadable = $this->unreadableTextViolation($sql, $tokens);

        if ($unreadable !== null) {
            throw new InvalidArgumentException($unreadable);
        }

        $quoted = $this->dollarQuotedRanges($sql);

        $segments = [];
        $start = 0;

        foreach ($tokens as $token) {
            if ($token->type === TokenType::Delimiter && $token->token !== '' && $token->position !== null) {
                // The dollar-quoted ranges and substr() count bytes.
                $offset = $this->tokenByteOffset($sql, $token->position);

                if ($this->isWithinRange($offset, $quoted)) {
                    continue;
                }

                $segments[] = substr($sql, $start, $offset - $start);
                $start = $offset + strlen($token->token);
            }
        }

        $segments[] = substr($sql, $start);

        $statements = [];

        foreach ($segments as $segment) {
            $trimmed = trim($segment);

            if ($trimmed === '' || $this->isCommentOnly($trimmed)) {
                continue;
            }

            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * Comment-free, whitespace-collapsed, literal-blanked view of a statement,
     * used for every textual guard check. String literals become '?' so their
     * contents can never satisfy (or dodge) a pattern, and dollar-quoted
     * bodies are masked into one such literal beforehand — they are string
     * literals too, and leaving them tokenized would let the text inside a
     * procedural body steer the guard decisions taken over this view.
     *
     * The body scan of a DO block turns that masking off: there the quoted
     * text is the subject of the check rather than noise around it.
     */
    private function normalize(string $sql, bool $maskDollarQuoted = true): string
    {
        $tokens = $this->lexTokens($maskDollarQuoted ? $this->maskDollarQuoted($sql) : $sql);
        $normalized = '';

        foreach ($tokens as $token) {
            if (in_array($token->type, [TokenType::Comment, TokenType::Whitespace], true)) {
                $normalized .= ' ';

                continue;
            }

            $normalized .= $token->type === TokenType::String ? "'?'" : $token->token;
        }

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }

    /**
     * Pull BEGIN/COMMIT markers off the statement list.
     *
     * @param  list<string>  $statements
     * @return array{0: list<string>, 1: bool, 2: list<string>}
     */
    private function extractTransaction(array $statements): array
    {
        $violations = [];

        $isBegin = fn (string $s) => (bool) preg_match('/^(BEGIN(\s+WORK)?|START\s+TRANSACTION)$/i', $s);
        $isCommit = fn (string $s) => (bool) preg_match('/^COMMIT(\s+WORK)?$/i', $s);
        $isRollback = fn (string $s) => (bool) preg_match('/^ROLLBACK\b/i', $s);

        $beginCount = count(array_filter($statements, $isBegin));
        $commitCount = count(array_filter($statements, $isCommit));

        foreach ($statements as $statement) {
            if ($isRollback($statement)) {
                $violations[] = 'ROLLBACK is not allowed: QueryProxy rolls the transaction back automatically when a statement fails.';
            }
        }

        $isTransaction = false;

        if ($beginCount === 0 && $commitCount === 0) {
            return [$statements, false, $violations];
        }

        if ($beginCount > 1 || $commitCount > 1) {
            $violations[] = 'Nested or repeated transaction blocks are not supported.';
        }

        if (! $isBegin($statements[0])) {
            $violations[] = 'The transaction must start with BEGIN (or START TRANSACTION) as the first statement.';
        }

        if (! $isCommit($statements[count($statements) - 1])) {
            $violations[] = 'The transaction must end with COMMIT as the last statement.';
        }

        if ($beginCount === 1 && $commitCount === 1 && $violations === []) {
            $isTransaction = true;
        }

        $executables = array_values(array_filter(
            $statements,
            fn (string $s) => ! $isBegin($s) && ! $isCommit($s) && ! $isRollback($s),
        ));

        return [$executables, $isTransaction, $violations];
    }

    /**
     * @return array{0: StatementInfo, 1: list<string>}
     */
    private function analyzeStatement(string $sql, int $position, ?DbDriver $driver, int $depth = 0): array
    {
        $violations = [];
        $label = "Statement {$position}";

        $normalized = $this->normalize($sql);

        foreach (self::FORBIDDEN_PATTERNS as $pattern => $message) {
            if (preg_match($pattern, $normalized)) {
                $violations[] = "{$label}: {$message}";
            }
        }

        foreach ($this->targetedViolations($sql, $driver) as $message) {
            $violations[] = "{$label}: {$message}";
        }

        $tokens = $this->significantTokens($sql);

        foreach ($this->dialectViolations($tokens, $driver) as $message) {
            $violations[] = "{$label}: {$message}";
        }

        // The keyword is read from the token stream, never from whitespace:
        // "EXPLAIN(ANALYZE)DELETE", "SELECT*INTO t2" and "(SELECT ...)" all
        // start with a keyword that a split on spaces would not see.
        [$keyword, $wrappingParens] = $this->leadingKeyword($tokens);

        // A statement held in a string (EXEC('...'), sp_executesql,
        // PREPARE, EXECUTE IMMEDIATE) or in a CTE body is judged with the
        // rules a top-level statement gets; a statement the guard cannot
        // read that way is rejected.
        [$dynamicViolations, $runsDynamicSql] = $this->dynamicSqlViolations($sql, $tokens, $position, $driver, $depth);
        array_push($violations, ...$dynamicViolations);

        if ($keyword === 'WITH') {
            array_push($violations, ...$this->commonTableExpressionViolations($sql, $tokens, $wrappingParens, $position, $driver, $depth));
        }

        // MariaDB's SET STATEMENT ... FOR <statement> runs the statement
        // after FOR; it is judged like a top-level statement of its own.
        if ($keyword === 'SET' && $this->isSetStatement($tokens, $wrappingParens)) {
            return $this->analyzeSetStatement($sql, $tokens, $wrappingParens, $position, $driver, $depth, $violations);
        }

        // Only a statement that runs has function-call effects; a plain
        // EXPLAIN plans its statement without running it. EXPLAIN ANALYZE
        // returns early and its inner statement is judged on its own.
        $executes = true;

        if (in_array($keyword, self::EXPLAIN_KEYWORDS, true)) {
            $options = $wrappingParens === 0
                ? $this->explainOptions($sql)
                : ['resolved' => false, 'analyze' => false, 'innerOffset' => strlen($sql)];

            if (! $options['resolved']) {
                $violations[] = "{$label}: {$keyword} with an option block QueryProxy cannot read is not allowed.";

                return [new StatementInfo($sql, $sql, StatementType::Write, false), $violations];
            }

            if ($options['analyze']) {
                return $this->analyzeExplainAnalyze($sql, $options['innerOffset'], $keyword, $position, $driver, $depth, $violations);
            }

            // EXPLAIN EXECUTE evaluates the parameter expressions of the
            // prepared statement even when it only plans (PostgreSQL's
            // ExplainExecuteQuery calls EvaluateParams), so its calls run.
            $executes = ! $this->explainIsPlanOnly($driver)
                || $this->explainsExecute(substr($sql, $options['innerOffset']));
        }

        // MariaDB's ANALYZE <statement> runs the statement like EXPLAIN
        // ANALYZE does; ANALYZE TABLE and the other maintenance spellings
        // fall through and keep their classification.
        if ($keyword === 'ANALYZE' && $this->analyzeMayRunStatement($driver)) {
            $analyze = $this->analyzeStatementForm($sql, $wrappingParens);

            if ($analyze !== null) {
                if (is_string($analyze)) {
                    $violations[] = "{$label}: {$analyze}";

                    return [new StatementInfo($sql, $sql, StatementType::Write, false), $violations];
                }

                return $this->analyzeExplainAnalyze($sql, $analyze, 'ANALYZE', $position, $driver, $depth, $violations);
            }
        }

        $functionWrites = false;

        if ($executes) {
            [$functionViolations, $functionWrites] = $this->functionCallEffects($tokens);

            foreach ($functionViolations as $message) {
                $violations[] = "{$label}: {$message}";
            }
        }

        // Only whether the parser reported an error is read, so it keeps the
        // first one: every later error would otherwise carry its own
        // exception and backtrace, hundreds of megabytes on a maximum-size
        // statement the parser stumbles over token after token.
        $parser = new class(new TokensList($this->lexTokens($sql))) extends Parser
        {
            public function error(string $msg, ?Token $token = null, int $code = 0): void
            {
                if ($this->errors === []) {
                    parent::error($msg, $token, $code);
                }
            }
        };
        $statement = $parser->statements[0] ?? null;
        $parsed = $statement !== null && $parser->errors === [];

        // The kept exception's backtrace references the parser and through
        // it every token: dropping it lets the parse tree be freed when this
        // method returns instead of lingering as cyclic garbage.
        $parser->errors = [];

        $type = $this->classify($statement, $keyword, $sql, $parsed);
        $isDdl = in_array($keyword, self::DDL_KEYWORDS, true);

        // SELECT ... INTO <table> creates a table: a DDL write that must
        // neither be labelled a read nor have a LIMIT appended.
        if (in_array($keyword, ['SELECT', 'WITH'], true) && $this->selectsIntoTable($tokens)) {
            $type = StatementType::Write;
            $isDdl = true;
        }

        // An allowed PRAGMA only reports on the schema or the build.
        if ($keyword === 'PRAGMA' && $wrappingParens === 0 && $this->pragmaViolation($tokens, 0) === null) {
            $type = StatementType::Read;
        }

        if ($functionWrites || $runsDynamicSql) {
            $type = StatementType::Write;
        }

        // WHERE guard for UPDATE / DELETE. A WHERE clause that is statically
        // always true ("WHERE 1=1", "WHERE id=1 OR true") counts as missing.
        $hasWhere = null;

        if ($statement instanceof UpdateStatement || $statement instanceof DeleteStatement) {
            $hasWhere = $statement->where !== null && $statement->where !== [];
        } elseif (! $parsed && in_array($keyword, ['UPDATE', 'DELETE'], true)) {
            $hasWhere = (bool) preg_match('/\bWHERE\b/i', $normalized);
        }

        if ($hasWhere === false) {
            $violations[] = "{$label}: ".strtoupper($keyword).' without a WHERE clause is not allowed.';
        } elseif ($hasWhere === true) {
            $alwaysTrue = $this->whereIsAlwaysTrue($tokens, $wrappingParens, $driver);

            if ($alwaysTrue === null) {
                $violations[] = "{$label}: ".strtoupper($keyword).' with a WHERE clause too complex to verify is not allowed.';
            } elseif ($alwaysTrue) {
                $violations[] = "{$label}: ".strtoupper($keyword).' with an always-true WHERE clause is not allowed.';
            }
        }

        // LIMIT guard for plain SELECTs (incl. read-only WITH ... SELECT).
        $preparedSql = $sql;
        $limitInjected = false;
        $limitClamped = false;

        // A parenthesised SELECT keeps its pre-existing behaviour and gets no
        // LIMIT appended after its closing parenthesis.
        if ($type === StatementType::Read && $wrappingParens === 0 && in_array($keyword, ['SELECT', 'WITH'], true)) {
            [$preparedSql, $limitInjected, $limitClamped, $limitViolations] = $this->applyLimitGuard($sql, $driver);

            foreach ($limitViolations as $violation) {
                $violations[] = "{$label}: {$violation}";
            }
        }

        return [
            new StatementInfo($sql, $preparedSql, $type, $parsed, $limitInjected, $limitClamped, $isDdl),
            $violations,
        ];
    }

    /**
     * Clause keywords that end the WHERE clause of an UPDATE or DELETE.
     */
    private const WHERE_CLAUSE_TERMINATORS = [
        'ORDER', 'LIMIT', 'RETURNING', 'OPTION', 'OFFSET', 'FETCH', 'GROUP', 'HAVING', 'WINDOW',
        'FOR', 'UNION', 'EXCEPT', 'INTERSECT', 'INTO',
    ];

    /**
     * Comparison operators a constant WHERE operand is judged with.
     */
    private const CONSTANT_COMPARISON_OPERATORS = ['=', '<>', '!=', '<', '>', '<=', '>=', '<=>'];

    /**
     * How deep the constant evaluator may recurse into a WHERE clause, and
     * how many tokens it may visit in all. A clause past either bound is
     * not judged "not always true": it is refused (fail closed), so a
     * deeply nested clause can neither exhaust the worker nor slip through.
     */
    private const CONSTANT_EVALUATION_MAX_DEPTH = 128;

    private const CONSTANT_EVALUATION_MAX_STEPS = 500_000;

    /**
     * Tokens the constant evaluator has visited for the current WHERE clause.
     */
    private int $constantEvaluationSteps = 0;

    /**
     * The lexer's tokens for each piece of SQL text read during the current
     * inspect() call, keyed by that text (see lexTokens()), and how deeply
     * inspect() is nested.
     *
     * @var array<string, list<Token>>
     */
    private array $lexedTokens = [];

    private int $inspectDepth = 0;

    /**
     * The SQL whose character byte offsets tokenByteOffset() last worked out,
     * and those offsets (see characterByteOffsets()).
     */
    private ?string $byteOffsetsSql = null;

    /**
     * @var list<int>|false|null
     */
    private array|false|null $byteOffsets = null;

    /**
     * Whether the WHERE clause of an UPDATE / DELETE is statically always
     * true, so that it filters nothing: "1=1", "true", "'a'='a'", "NOT 0",
     * "NULL IS NULL", an OR with such an operand, or an AND whose operands
     * all are. The check is best-effort: only literals (numbers, plain
     * single-quoted strings, TRUE / FALSE / NULL) are evaluated; a column,
     * a function call, a subquery, a cast or any other operator leaves the
     * operand unknown, and an unknown operand never makes the clause true.
     * "a = a" is not judged: a NULL in the column makes it false there.
     *
     * Null means the clause is too deeply nested or too long to evaluate
     * within the evaluator's bounds; the caller refuses the statement.
     *
     * The clause is read from the token stream at the statement's own
     * nesting level, so a WHERE inside a subquery of SET is not taken for
     * the statement's.
     *
     * @param  list<Token>  $tokens
     */
    private function whereIsAlwaysTrue(array $tokens, int $wrappingParens, ?DbDriver $driver): ?bool
    {
        $level = 0;
        $start = null;
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if ($this->isOperator($token, '(')) {
                $level++;

                continue;
            }

            if ($this->isOperator($token, ')')) {
                $level--;

                if ($start !== null && $level < $wrappingParens) {
                    break;
                }

                continue;
            }

            // RETURNING and other words the lexer does not know lex as a
            // bare name, so the clause keywords are matched on both types.
            if ($level !== $wrappingParens || ! in_array($token->type, [TokenType::Keyword, TokenType::None], true)) {
                continue;
            }

            $word = $this->firstWord($token);

            if ($start === null) {
                if ($word === 'WHERE') {
                    $start = $index + 1;
                }

                continue;
            }

            if (in_array($word, self::WHERE_CLAUSE_TERMINATORS, true)) {
                break;
            }
        }

        if ($start === null) {
            return false;
        }

        $this->constantEvaluationSteps = 0;

        try {
            $value = $this->constantValue($tokens, $start, $index, $driver);
        } catch (OverflowException) {
            return null;
        }

        return $value !== null && $this->constantTruth($value) === true;
    }

    /**
     * Count $steps visited tokens against the evaluator's budget and check
     * the recursion depth.
     *
     * @throws OverflowException when either bound is passed
     */
    private function chargeConstantEvaluation(int $steps, int $depth): void
    {
        $this->constantEvaluationSteps += $steps;

        if ($depth > self::CONSTANT_EVALUATION_MAX_DEPTH
            || $this->constantEvaluationSteps > self::CONSTANT_EVALUATION_MAX_STEPS) {
            throw new OverflowException('The WHERE clause is too complex to evaluate.');
        }
    }

    /**
     * The constant value of the expression $tokens[$from, $to), or null
     * when it is not a constant the guard can evaluate. A value is
     * [kind, value] with kind one of bool, number, string (its raw, quoted
     * spelling) or null. Precedence follows MySQL and PostgreSQL: OR binds
     * loosest, then AND, then NOT, then IS and the comparisons. MySQL's
     * "||" and "&&" are read as OR and AND on MySQL, MariaDB and an unknown
     * driver (also under PIPES_AS_CONCAT, where "||" concatenates: fail
     * closed); elsewhere "||" concatenates and leaves the operand unknown.
     *
     * The expression is addressed by index range, never copied, so memory
     * stays linear in the clause; $depth and the step budget bound the work
     * (chargeConstantEvaluation()). $operand is true below a comparison or
     * an IS test, where SQL Server reads TRUE / FALSE as column names.
     *
     * @param  list<Token>  $tokens
     * @return ?array{0: string, 1: mixed}
     *
     * @throws OverflowException when the clause passes the evaluator's bounds
     */
    private function constantValue(array $tokens, int $from, int $to, ?DbDriver $driver, int $depth = 0, bool $operand = false): ?array
    {
        $this->chargeConstantEvaluation(0, $depth);

        if ($from >= $to) {
            return null;
        }

        $logical = $driver === null || $driver === DbDriver::Mysql || $driver === DbDriver::Mariadb;

        $operands = $this->splitTopLevel($tokens, $from, $to, 'OR', $logical ? '||' : null);

        if (count($operands) > 1) {
            $allFalse = true;

            foreach ($operands as [$operandFrom, $operandTo]) {
                $value = $this->constantValue($tokens, $operandFrom, $operandTo, $driver, $depth + 1, $operand);
                $truth = $value === null ? null : $this->constantTruth($value);

                if ($truth === true) {
                    return ['bool', true];
                }

                $allFalse = $allFalse && $truth === false;
            }

            return $allFalse ? ['bool', false] : null;
        }

        $operands = $this->splitTopLevel($tokens, $from, $to, 'AND', $logical ? '&&' : null);

        if (count($operands) > 1) {
            foreach ($operands as [$operandFrom, $operandTo]) {
                $value = $this->constantValue($tokens, $operandFrom, $operandTo, $driver, $depth + 1, $operand);

                if ($value === null || $this->constantTruth($value) !== true) {
                    return null;
                }
            }

            return ['bool', true];
        }

        $first = $tokens[$from];

        if ($to - $from > 1 && $this->isWordAt($tokens, $from, 'NOT') && $this->tokenWords($first) === ['NOT']) {
            $value = $this->constantValue($tokens, $from + 1, $to, $driver, $depth + 1, $operand);
            $truth = $value === null ? null : $this->constantTruth($value);

            if ($value !== null && $value[0] === 'null') {
                return ['null', null];
            }

            return $truth === null ? null : ['bool', ! $truth];
        }

        $last = $to - 1;

        if ($this->isOperator($first, '(')) {
            $close = $this->matchingParenthesisAt($tokens, $from);
            $this->chargeConstantEvaluation(($close ?? count($tokens)) - $from, $depth);

            if ($close === $last) {
                return $this->constantValue($tokens, $from + 1, $last, $driver, $depth + 1, $operand);
            }
        }

        $isTest = $this->constantIsTest($tokens, $from, $to, $driver, $depth);

        if ($isTest !== false) {
            return $isTest;
        }

        $comparison = null;
        $level = 0;
        $this->chargeConstantEvaluation($to - $from, $depth);

        for ($index = $from; $index < $to; $index++) {
            $token = $tokens[$index];

            if ($this->isOperator($token, '(')) {
                $level++;
            } elseif ($this->isOperator($token, ')')) {
                $level--;
            } elseif ($level === 0 && $token->type === TokenType::Operator
                && in_array($token->token, self::CONSTANT_COMPARISON_OPERATORS, true)) {
                if ($comparison !== null) {
                    return null;
                }

                $comparison = $index;
            }
        }

        if ($comparison !== null) {
            $left = $this->constantValue($tokens, $from, $comparison, $driver, $depth + 1, true);

            if ($left === null) {
                return null;
            }

            $right = $this->constantValue($tokens, $comparison + 1, $to, $driver, $depth + 1, true);

            if ($right === null) {
                return null;
            }

            return $this->compareConstants($left, (string) $tokens[$comparison]->token, $right);
        }

        return $to - $from === 1 ? $this->literalValue($first, $driver, $operand) : null;
    }

    /**
     * The value of "<operand> IS [NOT] NULL | TRUE | FALSE" over
     * $tokens[$from, $to): null when the operand or the test cannot be
     * evaluated, false when the range is not such a test.
     *
     * @param  list<Token>  $tokens
     * @return array{0: string, 1: mixed}|false|null
     *
     * @throws OverflowException when the clause passes the evaluator's bounds
     */
    private function constantIsTest(array $tokens, int $from, int $to, ?DbDriver $driver, int $depth): array|false|null
    {
        $is = null;
        $level = 0;
        $this->chargeConstantEvaluation($to - $from, $depth);

        for ($index = $from; $index < $to; $index++) {
            $token = $tokens[$index];

            if ($this->isOperator($token, '(')) {
                $level++;
            } elseif ($this->isOperator($token, ')')) {
                $level--;
            } elseif ($level === 0 && $this->isWordAt($tokens, $index, 'IS')) {
                $is = $index;
            }
        }

        if ($is === null || $is === $from) {
            return false;
        }

        $words = [];

        for ($index = $is + 1; $index < $to; $index++) {
            $token = $tokens[$index];

            if (! in_array($token->type, [TokenType::Keyword, TokenType::Bool], true)) {
                return null;
            }

            array_push($words, ...$this->tokenWords($token));
        }

        $negated = ($words[0] ?? null) === 'NOT';

        if ($negated) {
            array_shift($words);
        }

        if (count($words) !== 1 || ! in_array($words[0], ['NULL', 'TRUE', 'FALSE'], true)) {
            return null;
        }

        $value = $this->constantValue($tokens, $from, $is, $driver, $depth + 1, true);

        if ($value === null) {
            return null;
        }

        if ($words[0] === 'NULL') {
            $result = $value[0] === 'null';
        } else {
            $truth = $this->constantTruth($value);

            if ($truth === null && $value[0] !== 'null') {
                return null;
            }

            $result = $truth === ($words[0] === 'TRUE');
        }

        return ['bool', $negated ? ! $result : $result];
    }

    /**
     * Split $tokens[$from, $to) at the keyword $word (or the operator
     * $operator) where it stands outside every parenthesis. Each part is
     * returned as its [from, to) index range.
     *
     * @param  list<Token>  $tokens
     * @return list<array{0: int, 1: int}>
     *
     * @throws OverflowException when the clause passes the evaluator's bounds
     */
    private function splitTopLevel(array $tokens, int $from, int $to, string $word, ?string $operator): array
    {
        $parts = [];
        $partFrom = $from;
        $level = 0;
        $this->chargeConstantEvaluation($to - $from, 0);

        for ($index = $from; $index < $to; $index++) {
            $token = $tokens[$index];

            if ($this->isOperator($token, '(')) {
                $level++;
            } elseif ($this->isOperator($token, ')')) {
                $level--;
            } elseif ($level === 0
                && (($this->isWordAt($tokens, $index, $word) && $this->tokenWords($token) === [$word])
                    || ($operator !== null && $this->isOperator($token, $operator)))) {
                $parts[] = [$partFrom, $index];
                $partFrom = $index + 1;
            }
        }

        $parts[] = [$partFrom, $to];

        return $parts;
    }

    /**
     * The constant a single token spells: a decimal number, a plain
     * single-quoted string, TRUE / FALSE or NULL. Hexadecimal and binary
     * numbers, double-quoted text (a name in PostgreSQL and SQL Server)
     * and prefixed strings (N'..', E'..', X'..') are not evaluated.
     *
     * T-SQL does not reserve TRUE and FALSE: as the operand of a
     * comparison or an IS test ($operand) they may name a column, so SQL
     * Server leaves them unknown there. Standing alone as a predicate
     * ("WHERE true") they are still read as constants: no column could
     * stand there in T-SQL, and refusing it fails closed.
     *
     * @return ?array{0: string, 1: mixed}
     */
    private function literalValue(Token $token, ?DbDriver $driver, bool $operand): ?array
    {
        return match (true) {
            $token->type === TokenType::Number
                && ($token->flags & (Token::FLAG_NUMBER_HEX | Token::FLAG_NUMBER_BINARY)) === 0
                && (is_int($token->value) || is_float($token->value)) => ['number', $token->value],
            $token->type === TokenType::String
                && ($token->flags & Token::FLAG_STRING_SINGLE_QUOTES) !== 0 => ['string', (string) $token->token],
            $token->type === TokenType::Bool => $operand && $driver === DbDriver::Sqlsrv
                ? null
                : ['bool', (bool) $token->value],
            $token->type === TokenType::Keyword && $this->tokenWords($token) === ['NULL'] => ['null', null],
            default => null,
        };
    }

    /**
     * Whether a constant makes a row pass a WHERE clause: true, false, or
     * null when that depends on the dialect (a string) or is NULL's
     * "unknown", which filters the row out like false but is not false.
     *
     * @param  array{0: string, 1: mixed}  $value
     */
    private function constantTruth(array $value): ?bool
    {
        return match ($value[0]) {
            'bool' => (bool) $value[1],
            'number' => $value[1] != 0,
            default => null,
        };
    }

    /**
     * The result of comparing two constants, or null when the dialects
     * would not agree on it: strings are only judged when they are spelled
     * identically (collations make 'a' = 'A' true in MySQL), and values of
     * different kinds are not compared.
     *
     * @param  array{0: string, 1: mixed}  $left
     * @param  array{0: string, 1: mixed}  $right
     * @return ?array{0: string, 1: mixed}
     */
    private function compareConstants(array $left, string $operator, array $right): ?array
    {
        if ($left[0] === 'null' || $right[0] === 'null') {
            return $operator === '<=>'
                ? ['bool', $left[0] === $right[0]]
                : ['null', null];
        }

        if ($left[0] !== $right[0]) {
            return null;
        }

        if ($left[0] === 'string') {
            if ($left[1] !== $right[1]) {
                return null;
            }

            $order = 0;
        } else {
            $order = $left[1] <=> $right[1];
        }

        return ['bool', match ($operator) {
            '=', '<=>' => $order === 0,
            '<>', '!=' => $order !== 0,
            '<' => $order < 0,
            '>' => $order > 0,
            '<=' => $order <= 0,
            default => $order >= 0,
        }];
    }

    /**
     * Judge one SQL Server batch. T-SQL needs no semicolon between
     * statements, so "SELECT 1 DELETE FROM t" is two statements to the
     * server; the batch is cut at every statement boundary
     * (sqlsrvBatchParts()) and each statement is judged on its own. The
     * batch takes the heaviest type and every violation of its statements.
     * A batch whose boundaries cannot be told is refused (fail closed).
     *
     * A batch that holds a single statement is judged exactly as before; a
     * split batch keeps its text and only the reads inside it get their TOP.
     *
     * @return array{0: StatementInfo, 1: list<string>}
     */
    private function analyzeSqlsrvBatch(string $sql, int $position, int $depth = 0): array
    {
        $label = "Statement {$position}";
        $parts = $this->sqlsrvBatchParts($sql);

        if (is_string($parts)) {
            return [new StatementInfo($sql, $sql, StatementType::Write, false), ["{$label}: {$parts}"]];
        }

        if (count($parts) === 1 && ! $parts[0][1]) {
            return $this->analyzeStatement($sql, $position, DbDriver::Sqlsrv, $depth);
        }

        $violations = [];
        $type = StatementType::Read;
        $isDdl = false;
        $limitInjected = false;
        $limitClamped = false;
        $length = mb_strlen($sql);
        $preparedSql = mb_substr($sql, 0, $parts[0][0]);

        foreach ($parts as $index => [$start, $isControl]) {
            $end = $parts[$index + 1][0] ?? $length;
            $slice = mb_substr($sql, $start, $end - $start);
            $text = rtrim($slice);
            $trailing = substr($slice, strlen($text));

            if ($isControl) {
                [$partType, $partViolations] = $this->analyzeSqlsrvControl($text, $label);
                $preparedSql .= $slice;
            } else {
                [$info, $partViolations] = $this->analyzeStatement($text, $position, DbDriver::Sqlsrv, $depth);
                $partType = $info->type;
                $isDdl = $isDdl || $info->isDdl;
                $limitInjected = $limitInjected || $info->limitInjected;
                $limitClamped = $limitClamped || $info->limitClamped;
                $preparedSql .= $info->preparedSql.$trailing;
            }

            if ($partType === StatementType::Write) {
                $type = StatementType::Write;
            }

            array_push($violations, ...$partViolations);
        }

        // A write batch runs as written: TOP only belongs to a read.
        if ($type === StatementType::Write) {
            $preparedSql = $sql;
            $limitInjected = false;
            $limitClamped = false;
        }

        return [
            new StatementInfo($sql, $preparedSql, $type, false, $limitInjected, $limitClamped, $isDdl),
            array_values(array_unique($violations)),
        ];
    }

    /**
     * A control-of-flow piece of a SQL Server batch — IF or WHILE with its
     * condition, ELSE, BEGIN, END, BEGIN/END TRY, BEGIN/END CATCH or a
     * label. It changes no data itself, but a condition can hold a subquery
     * and call functions, so it gets the same denylist and function checks
     * as a statement.
     *
     * @return array{0: StatementType, 1: list<string>}
     */
    private function analyzeSqlsrvControl(string $sql, string $label): array
    {
        $violations = [];
        $normalized = $this->normalize($sql);

        foreach (self::FORBIDDEN_PATTERNS as $pattern => $message) {
            if (preg_match($pattern, $normalized)) {
                $violations[] = "{$label}: {$message}";
            }
        }

        foreach ($this->targetedViolations($sql, DbDriver::Sqlsrv) as $message) {
            $violations[] = "{$label}: {$message}";
        }

        $tokens = $this->significantTokens($sql);

        foreach ($this->dialectViolations($tokens, DbDriver::Sqlsrv) as $message) {
            $violations[] = "{$label}: {$message}";
        }

        [$functionViolations, $functionWrites] = $this->functionCallEffects($tokens);

        foreach ($functionViolations as $message) {
            $violations[] = "{$label}: {$message}";
        }

        return [$functionWrites ? StatementType::Write : StatementType::Read, $violations];
    }

    /**
     * Words that start a statement in a SQL Server batch. All of them are
     * reserved in T-SQL, so a bare one is never a name; THROW is not
     * reserved and only starts a statement where a statement is expected.
     */
    private const SQLSRV_STATEMENT_KEYWORDS = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'MERGE', 'DECLARE', 'SET', 'IF', 'ELSE', 'WHILE',
        'BEGIN', 'END', 'EXEC', 'EXECUTE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'GRANT', 'REVOKE',
        'DENY', 'PRINT', 'RETURN', 'BREAK', 'CONTINUE', 'GOTO', 'RAISERROR', 'USE', 'BACKUP', 'RESTORE',
        'BULK', 'DBCC', 'KILL', 'SHUTDOWN', 'WAITFOR', 'OPEN', 'CLOSE', 'FETCH', 'DEALLOCATE', 'COMMIT',
        'ROLLBACK', 'SAVE', 'CHECKPOINT', 'RECONFIGURE', 'READTEXT', 'WRITETEXT', 'UPDATETEXT',
        'SETUSER', 'REVERT',
    ];

    /**
     * Compound keyword tokens of the lexer whose later word is a statement
     * keyword that does not start a statement there.
     */
    private const SQLSRV_STATEMENT_KEYWORD_COMPOUNDS = ['ON DELETE', 'ON UPDATE', 'FOR UPDATE'];

    /**
     * Statements whose clauses the batch parser follows token by token: a
     * word that is neither one of their clauses nor a name or alias where
     * one fits ends the batch with a refusal instead of being taken in.
     */
    private const SQLSRV_FOLLOWED_STATEMENTS = ['SELECT', 'WITH', 'INSERT', 'UPDATE', 'DELETE', 'MERGE'];

    /**
     * Keywords that open a clause or join two operands inside a followed
     * statement, an IF / WHILE condition or a RETURN value; an operand is
     * expected after them.
     */
    private const SQLSRV_CLAUSE_KEYWORDS = [
        'FROM', 'WHERE', 'AND', 'OR', 'NOT', 'IN', 'IS', 'LIKE', 'BETWEEN', 'ESCAPE', 'COLLATE', 'ON', 'BY',
        'GROUP', 'ORDER', 'HAVING', 'UNION', 'EXCEPT', 'INTERSECT', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL',
        'OUTER', 'CROSS', 'APPLY', 'HASH', 'LOOP', 'REMOTE', 'WHEN', 'THEN', 'INTO', 'VALUES', 'DEFAULT',
        'OUTPUT', 'USING', 'OVER', 'PARTITION', 'OFFSET', 'FOR', 'OPTION', 'PERCENT', 'PIVOT', 'UNPIVOT',
        'TABLESAMPLE', 'REPEATABLE', 'AT', 'TO',
    ];

    /**
     * Keywords that may only stand where an operand is expected.
     */
    private const SQLSRV_OPERAND_KEYWORDS = ['DISTINCT', 'ALL', 'ANY', 'SOME', 'EXISTS', 'CASE'];

    /**
     * Keywords that close an ORDER BY item or an OFFSET / FETCH clause.
     */
    private const SQLSRV_CLOSING_KEYWORDS = ['ASC', 'DESC', 'ROWS', 'ROW', 'ONLY'];

    /**
     * Keywords only right after the listed word; anywhere else they are
     * plain names.
     *
     * @var array<string, list<string>>
     */
    private const SQLSRV_CONTEXT_KEYWORDS = [
        'NEXT' => ['FETCH'], 'FIRST' => ['FETCH'], 'TIME' => ['AT'], 'ZONE' => ['TIME'], 'OF' => ['AS'],
        'TIES' => ['WITH'], 'SYSTEM_TIME' => ['FOR'], 'CONTAINED' => ['SYSTEM_TIME'], 'XML' => ['FOR'],
        'JSON' => ['FOR'], 'ROLLUP' => ['WITH'], 'CUBE' => ['WITH'],
    ];

    /**
     * Words that are values on their own in a condition or RETURN value,
     * where any other bare word would be a name outside a query.
     */
    private const SQLSRV_VALUE_KEYWORDS = ['NULL', 'CURRENT_TIMESTAMP', 'CURRENT_USER', 'SESSION_USER', 'SYSTEM_USER', 'USER'];

    /**
     * Expression states after which the statement being followed is
     * complete, so the next statement may start.
     */
    private const SQLSRV_COMPLETE_STATES = ['complete', 'closed', 'aliased', 'typed', 'sized', 'done'];

    /**
     * States of the DECLARE and SET option grammars, which
     * sqlsrvHeaderStep() follows instead of the expression rules.
     */
    private const SQLSRV_HEADER_STATES = [
        'variable', 'type', 'type_name', 'typed', 'type_args', 'sized', 'table', 'table_args', 'cursor_name',
        'cursor', 'cursor_for', 'option', 'statistics', 'switch', 'switch_next', 'identity', 'identity_name',
        'isolation', 'level', 'isolation_level', 'read', 'repeatable', 'setting', 'done',
    ];

    /**
     * SET options that take ON or OFF, alone or in a comma-separated list;
     * the last four are the kinds of SET STATISTICS.
     */
    private const SQLSRV_SET_SWITCHES = [
        'NOCOUNT', 'XACT_ABORT', 'ANSI_DEFAULTS', 'ANSI_NULL_DFLT_OFF', 'ANSI_NULL_DFLT_ON', 'ANSI_NULLS',
        'ANSI_PADDING', 'ANSI_WARNINGS', 'ARITHABORT', 'ARITHIGNORE', 'CONCAT_NULL_YIELDS_NULL',
        'CURSOR_CLOSE_ON_COMMIT', 'FMTONLY', 'FORCEPLAN', 'IMPLICIT_TRANSACTIONS', 'NOEXEC', 'NUMERIC_ROUNDABORT',
        'PARSEONLY', 'QUOTED_IDENTIFIER', 'REMOTE_PROC_TRANSACTIONS', 'SHOWPLAN_ALL', 'SHOWPLAN_TEXT',
        'SHOWPLAN_XML', 'NO_BROWSETABLE', 'IO', 'TIME', 'XML', 'PROFILE',
    ];

    /**
     * SET options that take exactly one value.
     */
    private const SQLSRV_SET_SETTINGS = [
        'DATEFIRST', 'DATEFORMAT', 'DEADLOCK_PRIORITY', 'LANGUAGE', 'LOCK_TIMEOUT', 'ROWCOUNT', 'TEXTSIZE',
        'QUERY_GOVERNOR_COST_LIMIT', 'CONTEXT_INFO',
    ];

    /**
     * Options between CURSOR and FOR in a DECLARE CURSOR.
     */
    private const SQLSRV_CURSOR_OPTIONS = [
        'LOCAL', 'GLOBAL', 'FORWARD_ONLY', 'SCROLL', 'STATIC', 'KEYSET', 'DYNAMIC', 'FAST_FORWARD', 'READ_ONLY',
        'SCROLL_LOCKS', 'OPTIMISTIC', 'TYPE_WARNING',
    ];

    /**
     * Object types after which "IF EXISTS" belongs to a DROP, ALTER or
     * CREATE statement instead of starting an IF.
     */
    private const SQLSRV_OBJECT_TYPES = [
        'TABLE', 'VIEW', 'PROCEDURE', 'PROC', 'FUNCTION', 'INDEX', 'TRIGGER', 'SCHEMA', 'DATABASE',
        'SEQUENCE', 'TYPE', 'SYNONYM', 'USER', 'ROLE', 'COLUMN', 'CONSTRAINT', 'DEFAULT', 'RULE',
        'ASSEMBLY', 'STATISTICS', 'LOGIN', 'AGGREGATE', 'SECURITY POLICY', 'POLICY',
    ];

    /**
     * Words that start a T-SQL statement QueryProxy does not split on: the
     * Service Broker statements, the trigger switches and ADD SIGNATURE.
     * None of them is reserved, so a body would otherwise take one as a
     * name and the statement after it in. Inside a body they are refused
     * as names; a bracketed name is still a name, and ALTER keeps ADD,
     * DISABLE and ENABLE as clauses (SQLSRV_BODY_CONTEXT_KEYWORDS).
     *
     * @var list<string>
     */
    private const SQLSRV_UNLISTED_STATEMENT_HEADS = ['ADD', 'DISABLE', 'ENABLE', 'GET', 'MOVE', 'RECEIVE', 'SEND'];

    /**
     * Statement heads that take no name: their body starts after an item,
     * so a bare word right after them is refused.
     *
     * @var list<string>
     */
    private const SQLSRV_NAMELESS_HEADS = ['CHECKPOINT', 'RECONFIGURE', 'REVERT', 'SETUSER', 'SHUTDOWN'];

    /**
     * Keywords a statement body that is not followed clause by clause — EXEC,
     * USE, DBCC, RAISERROR, the DDL statements and every other statement
     * head — may hold besides the clause, operand and object type keywords.
     * A name is expected after them, so the next bare word is a name. Any
     * other bare word where no name is expected is refused: a body cannot
     * take in a statement QueryProxy does not know.
     */
    private const SQLSRV_BODY_KEYWORDS = [
        'AS', 'WITH', 'WITHOUT', 'CONSTRAINT', 'PRIMARY', 'KEY', 'FOREIGN', 'REFERENCES', 'UNIQUE',
        'CLUSTERED', 'NONCLUSTERED', 'COLUMNSTORE', 'FULLTEXT', 'CHECK', 'NOCHECK', 'NO', 'INCLUDE',
        'AUTHORIZATION', 'MEMBER', 'START', 'INCREMENT', 'MINVALUE', 'MAXVALUE', 'CACHE', 'SWITCH',
        'TRANSFER', 'MODIFY', 'FILE', 'FILEGROUP', 'ENCRYPTION', 'DECRYPTION', 'PASSWORD', 'MASTER',
        'SYMMETRIC', 'ASYMMETRIC', 'CERTIFICATE', 'GLOBAL', 'LOCAL', 'RESULT', 'SETS', 'LOG', 'PERIOD',
    ];

    /**
     * Keywords that may end a statement body: after them a bare word is
     * refused unless it calls a function, so a statement QueryProxy does not
     * know cannot follow them unseen. The data types are among them.
     */
    private const SQLSRV_BODY_FINAL_KEYWORDS = [
        'NULL', 'DEFAULT', 'ALL', 'OFF', 'CASCADE', 'ACTION', 'RECOMPILE', 'IDENTITY', 'PERSISTED', 'SPARSE',
        'ROWGUIDCOL', 'CYCLE', 'NOWAIT', 'SIMPLE', 'REBUILD', 'REORGANIZE', 'RESUME', 'PAUSE', 'ABORT', 'OUT',
        'OUTPUT', 'READONLY', 'REPLICATION', 'FULL', 'LEFT',
        'RIGHT', 'ASC', 'DESC', 'CURRENT_USER', 'CURRENT_TIMESTAMP', 'SESSION_USER', 'SYSTEM_USER',
        'BIGINT', 'INT', 'INTEGER', 'SMALLINT', 'TINYINT', 'BIT', 'DECIMAL', 'DEC', 'NUMERIC', 'MONEY',
        'SMALLMONEY', 'FLOAT', 'REAL', 'DATE', 'DATETIME', 'DATETIME2', 'DATETIMEOFFSET', 'SMALLDATETIME',
        'TIME', 'CHAR', 'CHARACTER', 'VARCHAR', 'NCHAR', 'NVARCHAR', 'TEXT', 'NTEXT', 'BINARY', 'VARBINARY',
        'IMAGE', 'UNIQUEIDENTIFIER', 'XML', 'SQL_VARIANT', 'SYSNAME', 'ROWVERSION', 'TIMESTAMP',
        'HIERARCHYID', 'GEOGRAPHY', 'GEOMETRY',
    ];

    /**
     * Body keywords only in the body of the listed statement heads: ALTER
     * TABLE ... ADD, ALTER INDEX ... DISABLE, ALTER TABLE ... ENABLE
     * TRIGGER. Anywhere else they would start ADD SIGNATURE, DISABLE
     * TRIGGER or ENABLE TRIGGER.
     *
     * @var array<string, list<string>>
     */
    private const SQLSRV_BODY_CONTEXT_KEYWORDS = [
        'ADD' => ['ALTER'], 'DISABLE' => ['ALTER'], 'ENABLE' => ['ALTER'], 'MOVE' => ['RESTORE'],
    ];

    /**
     * Where each statement and control-of-flow piece of a SQL Server batch
     * starts: a list of [character position, is control piece], in order;
     * each piece runs to the start of the next one. A string is returned —
     * the reason — when the boundaries cannot be told: unbalanced
     * parentheses, BEGIN/END, TRY/CATCH or CASE, an ELSE without an IF, a
     * data-changing statement inside parentheses, or a token that cannot
     * start a statement where one is expected.
     *
     * A statement keyword starts a new statement unless the statement in
     * progress takes it: SELECT after UNION / EXCEPT / INTERSECT or as the
     * source of an INSERT, the main query of a WITH, the actions of a MERGE,
     * the SET of an UPDATE, ON DELETE / ON UPDATE actions, IF EXISTS in DDL,
     * the clauses of ALTER and GRANT, OFFSET ... FETCH and a cursor's FOR
     * SELECT. CREATE / ALTER of a module (procedure, function, trigger,
     * view) is the last statement of its batch, so it takes the rest.
     *
     * @return list<array{0: int, 1: bool}>|string
     */
    private function sqlsrvBatchParts(string $sql): array|string
    {
        $ranges = $this->dialectLexicalRanges($sql, DbDriver::Sqlsrv);

        if ($ranges === null) {
            return 'QueryProxy cannot tell where a string, quoted name or comment in this SQL Server batch ends.';
        }

        $tokens = [];

        foreach ($this->lexTokens($sql) as $token) {
            if ($token->position !== null
                && ! in_array($token->type, [TokenType::Comment, TokenType::Whitespace, TokenType::Delimiter], true)) {
                $tokens[] = $token;
            }
        }

        $parts = [];
        $depth = 0;
        $cases = 0;
        $blocks = [];
        $ifs = 0;
        $outerIfs = [];
        $expectStatement = false;
        $statement = null;
        $expression = null;
        $unknownStart = false;
        $skip = -1;
        $skipBefore = -1;

        foreach ($tokens as $index => $token) {
            $position = (int) $token->position;

            if ($index <= $skip || $position < $skipBefore) {
                continue;
            }

            $bracketed = $this->isWithinSortedRanges($position, $ranges['brackets']);
            $isWord = ! $bracketed && $this->isWordAt($tokens, $index, $this->firstWord($token));
            $words = $isWord ? $this->tokenWords($token) : [];
            $word = $words[0] ?? '';

            if (count($words) > 1 && array_intersect(array_slice($words, 1), self::SQLSRV_STATEMENT_KEYWORDS) !== []
                && ! in_array(implode(' ', $words), self::SQLSRV_STATEMENT_KEYWORD_COMPOUNDS, true)) {
                return "The keyword sequence \"{$token->token}\" hides a statement keyword, so QueryProxy cannot tell where the statements of this SQL Server batch end.";
            }

            if ($word === 'CASE') {
                $cases++;
            }

            if ($parts === [] && ! in_array($word, self::SQLSRV_STATEMENT_KEYWORDS, true) && ! in_array($word, ['THROW', 'WITH'], true)
                && $token->type !== TokenType::Label) {
                $parts[] = [$position, false];
                $statement = $this->sqlsrvStatementState('');
                $isParenthesized = ! $bracketed && $this->isOperator($token, '(');
                // Only a parenthesized query is followed token by token; a
                // statement that starts with any other word is one QueryProxy
                // does not know, so the batch must hold nothing after it.
                $expression = $isParenthesized ? $this->sqlsrvExpression('statement', '') : null;
                $unknownStart = ! $isParenthesized;
            }

            if (! $bracketed && $this->isOperator($token, '(')) {
                if ($expectStatement) {
                    return $this->sqlsrvUnexpectedToken($token);
                }

                if ($depth === 0 && $expression !== null) {
                    $expression = $this->sqlsrvExpressionStep($expression, 'open');

                    if ($expression === null) {
                        return $this->sqlsrvUnknownToken($token);
                    }
                }

                $depth++;

                continue;
            }

            if (! $bracketed && $this->isOperator($token, ')')) {
                if (--$depth < 0) {
                    return 'Unbalanced parentheses are not allowed: QueryProxy cannot tell where the statements of this SQL Server batch end.';
                }

                if ($depth === 0 && $expression !== null) {
                    $expression = $this->sqlsrvExpressionStep($expression, 'close');
                }

                continue;
            }

            if ($word === 'END' && $cases > 0) {
                $cases--;

                if ($depth === 0 && $expression !== null) {
                    $expression = ['state' => 'complete', 'last' => 'END'] + $expression;
                }

                continue;
            }

            if ($word === 'ELSE' && $cases > 0) {
                if ($depth === 0 && $expression !== null) {
                    $expression = ['state' => 'operand', 'last' => 'ELSE'] + $expression;
                }

                continue;
            }

            if ($depth > 0) {
                if (in_array($word, ['INSERT', 'UPDATE', 'DELETE', 'MERGE'], true)
                    && $this->sqlsrvPreviousWord($tokens, $index) !== 'ON') {
                    return 'A data-changing statement inside parentheses is not allowed: QueryProxy cannot tell where it ends in this SQL Server batch.';
                }

                continue;
            }

            if ($token->type === TokenType::Label) {
                if ($expression !== null && ($expression['mode'] === 'condition'
                    || (! in_array($expression['mode'], ['return', 'body'], true)
                        && ! in_array($expression['state'], self::SQLSRV_COMPLETE_STATES, true)))) {
                    return $this->sqlsrvUnknownToken($token);
                }

                $parts[] = [$position, true];
                $statement = null;
                $expression = null;
                $expectStatement = true;

                continue;
            }

            // "SET NOCOUNT ON DELETE FROM t" lexes ON DELETE as one token:
            // outside a foreign key the ON ends a statement and the DELETE
            // (or UPDATE) starts the next one, inside the token.
            if (count($words) > 1 && $words[0] === 'ON' && in_array($words[1], ['DELETE', 'UPDATE'], true)) {
                if ($statement !== null && $statement['references']) {
                    continue;
                }

                if ($expectStatement || $cases > 0) {
                    return $this->sqlsrvUnexpectedToken($token);
                }

                // Only a SET option such as SET NOCOUNT ends in ON.
                if ($expression === null || ! in_array($expression['state'], ['switch', 'identity_name'], true)) {
                    return $this->sqlsrvUnknownToken($token);
                }

                preg_match('/^\S+\s+/u', (string) $token->token, $match);
                $parts[] = [$position + mb_strlen($match[0] ?? ''), false];
                $statement = $this->sqlsrvStatementState($words[1]);
                $expression = $this->sqlsrvExpression('statement', $words[1]);

                continue;
            }

            // THROW is not reserved: it starts a statement only where one is
            // expected or where a condition or RETURN value is complete.
            $isKeyword = in_array($word, self::SQLSRV_STATEMENT_KEYWORDS, true)
                || ($expectStatement && in_array($word, ['THROW', 'WITH'], true))
                || ($parts === [] && in_array($word, ['THROW', 'WITH'], true))
                || ($word === 'THROW' && $expression !== null && ($expression['mode'] === 'body'
                    || ($expression['mode'] !== 'statement'
                        && in_array($expression['state'], self::SQLSRV_COMPLETE_STATES, true))));

            if (! $isKeyword) {
                if ($expectStatement) {
                    return $this->sqlsrvUnexpectedToken($token);
                }

                if ($expression !== null) {
                    [$expression, $skipped, $skippedBefore] = $this->sqlsrvFollowExpression($expression, $tokens, $index, $words, $ranges);

                    if ($expression === null) {
                        return $this->sqlsrvUnknownToken($token);
                    }

                    $skip = max($skip, $skipped);
                    $skipBefore = max($skipBefore, $skippedBefore);
                }

                if ($statement !== null) {
                    $statement = $this->sqlsrvAdvanceStatement($statement, $tokens, $index, $word);
                }

                continue;
            }

            if ($expression !== null && $expression['state'] === 'as') {
                return $this->sqlsrvUnknownToken($token);
            }

            if ($statement !== null && $this->sqlsrvContinuesStatement($statement, $tokens, $index, $words)) {
                $statement = $this->sqlsrvAdvanceStatement($statement, $tokens, $index, $word);

                if ($expression !== null) {
                    // A body takes a name after the keyword; INSERT ... EXEC
                    // runs a procedure followed as a body; the DELETE action of a MERGE is complete alone;
                    // the query of a DECLARE CURSOR is followed as a SELECT.
                    $expression = match (true) {
                        $expression['mode'] === 'body' => ['state' => 'start', 'last' => $word, 'argument' => false] + $expression,
                        in_array($word, ['EXEC', 'EXECUTE'], true) => $this->sqlsrvBodyExpression($word),
                        $expression['state'] === 'cursor_for' => $this->sqlsrvFollowWords(
                            $this->sqlsrvExpression('statement', $word), array_slice($words, 1), $tokens, $index,
                        ) ?? ['state' => 'invalid'] + $expression,
                        $expression['mode'] === 'declare' || $expression['mode'] === 'option' => ['state' => 'invalid'] + $expression,
                        $word === 'DELETE' && $statement['merge'] => ['state' => 'complete', 'last' => $word, 'top' => false] + $expression,
                        default => ['state' => 'operand', 'last' => $word, 'top' => false] + $expression,
                    };

                    if ($expression['state'] === 'invalid') {
                        return $this->sqlsrvUnknownToken($token);
                    }
                }

                continue;
            }

            // A condition and a followed statement must be complete before
            // the next statement starts; a bare RETURN or THROW takes no value.
            if ($expression !== null && ! in_array($expression['mode'], ['return', 'body'], true)
                && ! in_array($expression['state'], self::SQLSRV_COMPLETE_STATES, true)) {
                return $this->sqlsrvUnknownToken($token);
            }

            if ($cases > 0) {
                return "A statement keyword ({$word}) inside a CASE expression is not allowed: QueryProxy cannot tell where the statements of this SQL Server batch end.";
            }

            $next = $tokens[$index + 1] ?? null;
            $nextWord = $next !== null ? $this->firstWord($next) : '';

            if (in_array($word, ['COMMIT', 'ROLLBACK', 'SAVE'], true)
                || ($word === 'BEGIN' && in_array($nextWord, ['TRAN', 'TRANSACTION', 'DISTRIBUTED'], true))) {
                return self::SQLSRV_TRANSACTION_CONTROL_VIOLATION;
            }

            $statement = null;
            $expression = null;
            $expectStatement = false;

            switch ($word) {
                case 'BREAK':
                case 'CONTINUE':
                    $parts[] = [$position, true];
                    $expectStatement = true;

                    continue 2;
                case 'GOTO':
                    // GOTO takes exactly one label name.
                    if ($next === null || $nextWord === '' || in_array($nextWord, self::SQLSRV_STATEMENT_KEYWORDS, true)
                        || ! $this->isWordAt($tokens, $index + 1, $nextWord)
                        || $this->isWithinSortedRanges((int) $next->position, $ranges['brackets'])) {
                        return $this->sqlsrvUnexpectedToken($next ?? $token);
                    }

                    $parts[] = [$position, true];
                    $skip = $index + 1;
                    $expectStatement = true;

                    continue 2;
                case 'RETURN':
                case 'IF':
                case 'WHILE':
                    $ifs += $word === 'IF' ? 1 : 0;
                    $parts[] = [$position, true];
                    $expression = $this->sqlsrvFollowWords(
                        $this->sqlsrvExpression($word === 'RETURN' ? 'return' : 'condition', $word),
                        array_slice($words, 1), $tokens, $index,
                    );

                    if ($expression === null) {
                        return $this->sqlsrvUnknownToken($token);
                    }

                    continue 2;
                case 'ELSE':
                    // Each ELSE takes the innermost IF of its block that has none yet.
                    if ($ifs === 0) {
                        return 'ELSE without a preceding IF is not allowed: QueryProxy cannot tell where the statements of this SQL Server batch end.';
                    }

                    $ifs--;
                    $parts[] = [$position, true];
                    $expectStatement = true;

                    continue 2;
                case 'BEGIN':
                    // BEGIN DIALOG, BEGIN CONVERSATION and the other forms are
                    // refused below: the word after them starts no statement.
                    $blocks[] = in_array($nextWord, ['TRY', 'CATCH'], true) ? $nextWord : 'BLOCK';
                    $skip = in_array($nextWord, ['TRY', 'CATCH'], true) ? $index + 1 : $skip;
                    $outerIfs[] = $ifs;
                    $ifs = 0;

                    $parts[] = [$position, true];
                    $expectStatement = true;

                    continue 2;
                case 'END':
                    $kind = in_array($nextWord, ['TRY', 'CATCH'], true) ? $nextWord : 'BLOCK';

                    if (array_pop($blocks) !== $kind) {
                        return 'BEGIN ... END, BEGIN TRY ... END TRY and BEGIN CATCH ... END CATCH blocks that do not match are not allowed: QueryProxy cannot tell where the statements of this SQL Server batch end.';
                    }

                    $skip = $kind !== 'BLOCK' ? $index + 1 : $skip;
                    $ifs = (int) array_pop($outerIfs);
                    $parts[] = [$position, true];
                    $expectStatement = true;

                    continue 2;
            }

            $parts[] = [$position, false];
            $statement = $this->sqlsrvStatementState($word);

            $expression = $this->sqlsrvFollowWords(
                $this->sqlsrvStatementExpression($word, $next, $nextWord),
                array_slice($words, 1), $tokens, $index,
            );

            if ($expression === null) {
                return $this->sqlsrvUnknownToken($token);
            }

            if (in_array($word, ['CREATE', 'ALTER'], true) && $this->sqlsrvDefinesModule($tokens, $index)) {
                if ($blocks !== []) {
                    return 'A procedure, function, trigger or view definition inside BEGIN ... END is not allowed: QueryProxy cannot tell where the statements of this SQL Server batch end.';
                }

                return $parts;
            }
        }

        if ($depth !== 0) {
            return 'Unbalanced parentheses are not allowed: QueryProxy cannot tell where the statements of this SQL Server batch end.';
        }

        if ($cases !== 0) {
            return 'A CASE expression without its END is not allowed: QueryProxy cannot tell where the statements of this SQL Server batch end.';
        }

        if ($blocks !== []) {
            return 'BEGIN ... END, BEGIN TRY ... END TRY and BEGIN CATCH ... END CATCH blocks that do not match are not allowed: QueryProxy cannot tell where the statements of this SQL Server batch end.';
        }

        if ($unknownStart && count($parts) > 1) {
            return 'The batch starts with a statement QueryProxy does not know: QueryProxy cannot tell where the statements of this SQL Server batch end.';
        }

        return $parts === [] ? [[0, false]] : $parts;
    }

    private function sqlsrvUnexpectedToken(Token $token): string
    {
        return "QueryProxy expects a statement before \"{$token->token}\" and cannot tell where the statements of this SQL Server batch end.";
    }

    private function sqlsrvUnknownToken(Token $token): string
    {
        return "\"{$token->token}\" is not a statement QueryProxy knows, nor part of the statement before it: QueryProxy cannot tell where the statements of this SQL Server batch end.";
    }

    /**
     * A fresh expression state: the parser follows a SELECT, WITH, INSERT,
     * UPDATE, DELETE or MERGE statement, an IF / WHILE condition or a
     * RETURN value through its depth-0 tokens and expects an operand first.
     *
     * @param  'statement'|'condition'|'return'|'value'|'declare'|'option'|'body'  $mode
     * @return array{mode: string, state: string, last: string, top: bool, head?: string, argument?: bool}
     */
    private function sqlsrvExpression(string $mode, string $head): array
    {
        return ['mode' => $mode, 'state' => 'operand', 'last' => $head, 'top' => false];
    }

    /**
     * The body of a statement that is not followed clause by clause: the
     * names, values and body keywords after its head word. A head that
     * takes no name starts after an item; USE takes exactly one name,
     * whatever the word; EXEC and EXECUTE start at the module, whose name
     * may take one bare word as its first argument.
     *
     * @return array{mode: string, state: string, last: string, top: bool, head?: string, argument?: bool}
     */
    private function sqlsrvBodyExpression(string $head): array
    {
        $state = match (true) {
            in_array($head, self::SQLSRV_NAMELESS_HEADS, true) => 'item',
            $head === 'USE' => 'name',
            in_array($head, ['EXEC', 'EXECUTE'], true) => 'module',
            default => 'start',
        };

        return ['state' => $state, 'head' => $head, 'argument' => false]
            + $this->sqlsrvExpression('body', $head);
    }

    /**
     * The expression that follows the statement starting with $word. A
     * SELECT, WITH, INSERT, UPDATE, DELETE or MERGE is followed clause by
     * clause; PRINT takes a value and THROW an optional list of values;
     * DECLARE and SET follow their own grammars; every other statement is
     * followed as a body. None of them can take in a statement QueryProxy
     * does not know.
     *
     * @return array{mode: string, state: string, last: string, top: bool, head?: string, argument?: bool}
     */
    private function sqlsrvStatementExpression(string $word, ?Token $next, string $nextWord): array
    {
        $assignsVariable = $next !== null && $next->type === TokenType::Symbol
            && str_starts_with((string) $next->token, '@');

        return match (true) {
            // UPDATE STATISTICS is maintenance, not a followed UPDATE.
            $word === 'UPDATE' && $nextWord === 'STATISTICS' => $this->sqlsrvBodyExpression($word),
            in_array($word, self::SQLSRV_FOLLOWED_STATEMENTS, true) => $this->sqlsrvExpression('statement', $word),
            $word === 'PRINT', $word === 'SET' && $assignsVariable => $this->sqlsrvExpression('value', $word),
            $word === 'THROW' => $this->sqlsrvExpression('return', $word),
            $word === 'DECLARE' => ['state' => 'variable'] + $this->sqlsrvExpression('declare', $word),
            $word === 'SET' => ['state' => 'option'] + $this->sqlsrvExpression('option', $word),
            default => $this->sqlsrvBodyExpression($word),
        };
    }

    /**
     * One step of a statement body. In the "start" state a name is
     * expected, so any bare word is one; in the "item" state a name,
     * value or final keyword ended the last item, and only a body keyword,
     * a function call or a qualified name such as a user-defined type may
     * follow: no statement starts with a word followed by "(" or ".". A
     * bracketed name is never a statement, so it fits anywhere.
     *
     * The "name" state (after USE) takes exactly one name. The "module"
     * state (after EXEC) takes the module name — any word, a qualified
     * name, "@rc =" before it or a parenthesised string; right after the
     * module name one bare word may be the first argument, but only when
     * no further bare word follows it, so the first words of a statement
     * are never read as an argument. A word that starts a statement
     * QueryProxy does not split on is never a name.
     *
     * @param  array{mode: string, state: string, last: string, top: bool, head?: string, argument?: bool}  $expression
     * @return array{mode: string, state: string, last: string, top: bool, head?: string, argument?: bool}|null
     */
    private function sqlsrvBodyStep(array $expression, string $unit, string $word, string $nextWord, bool $isCallable): ?array
    {
        $state = $expression['state'];
        $head = $expression['head'] ?? '';
        $argument = false;

        if ($unit === 'word') {
            $isContextKeyword = in_array($head, self::SQLSRV_BODY_CONTEXT_KEYWORDS[$word] ?? [], true);

            if (! $isContextKeyword && in_array($word, self::SQLSRV_UNLISTED_STATEMENT_HEADS, true)) {
                return null;
            }

            $isBodyKeyword = $isContextKeyword
                || in_array($word, self::SQLSRV_BODY_KEYWORDS, true)
                || in_array($word, self::SQLSRV_CLAUSE_KEYWORDS, true)
                || in_array($word, self::SQLSRV_OPERAND_KEYWORDS, true)
                || in_array($word, self::SQLSRV_OBJECT_TYPES, true)
                || in_array($word, self::SQLSRV_STATEMENT_KEYWORDS, true);
            $endsArgument = preg_match('/^[A-Z_#][A-Z0-9_#$@]*$/', $nextWord) !== 1
                || in_array($nextWord, self::SQLSRV_STATEMENT_KEYWORDS, true)
                || in_array($nextWord, ['WITH', 'THROW'], true);

            $next = match (true) {
                $state === 'name' => 'item',
                $state === 'module' => $isCallable ? 'module' : 'item',
                $state === 'returns' => null,
                in_array($word, self::SQLSRV_BODY_FINAL_KEYWORDS, true) => 'item',
                $isBodyKeyword => 'start',
                $state === 'start', $isCallable => 'item',
                ($expression['argument'] ?? false) && $endsArgument => 'item',
                default => null,
            };

            $argument = $state === 'module' && ! $isCallable;
        } else {
            $next = match (true) {
                $state === 'name' => $unit === 'name' ? 'item' : null,
                $state === 'module' => match ($unit) {
                    'name' => $isCallable ? 'module' : 'item',
                    'variable' => 'returns',
                    'open', 'dot' => 'module',
                    'close' => 'item',
                    default => null,
                },
                $state === 'returns' => $unit === 'equals' ? 'module' : null,
                in_array($unit, ['comma', 'operator', 'dot', 'equals'], true) => 'start',
                $unit === 'open' => $state,
                default => 'item',
            };

            $argument = $state === 'module' && $unit === 'name' && ! $isCallable;
        }

        if ($next === null) {
            return null;
        }

        $expression['state'] = $next;
        $expression['argument'] = $argument;

        return $expression;
    }

    /**
     * One step of the DECLARE and SET option grammars: the next state, or
     * null when the unit has no place there.
     *
     * DECLARE: @name [AS] type [(size)] [= value], @name [AS] TABLE (...),
     *
     * @name CURSOR, or name [INSENSITIVE] [SCROLL] CURSOR [options] FOR
     * query; a comma starts the next variable. SET: a list of ON / OFF
     * options, STATISTICS kinds, IDENTITY_INSERT table, TRANSACTION
     * ISOLATION LEVEL or one valued option. An option QueryProxy does not
     * know is refused.
     */
    private function sqlsrvHeaderStep(string $state, string $unit, string $word): ?string
    {
        $isName = $unit === 'name'
            || ($unit === 'word' && ! in_array($word, self::SQLSRV_STATEMENT_KEYWORDS, true));
        $isWord = fn (string ...$candidates): bool => $unit === 'word' && in_array($word, $candidates, true);

        return match ($state) {
            'variable' => match (true) {
                $unit === 'variable' => 'type',
                $isName => 'cursor_name',
                default => null,
            },
            'cursor_name' => match (true) {
                $isWord('CURSOR') => 'cursor',
                $isWord('INSENSITIVE', 'SCROLL') => 'cursor_name',
                default => null,
            },
            'type', 'type_name' => match (true) {
                $state === 'type' && $isWord('AS') => 'type_name',
                $isWord('TABLE') => 'table',
                $isWord('CURSOR') => 'sized',
                $isName => 'typed',
                default => null,
            },
            'typed', 'sized' => match (true) {
                $state === 'typed' && $unit === 'open' => 'type_args',
                $state === 'typed' && $unit === 'dot' => 'type_name',
                $unit === 'equals' => 'operand',
                $unit === 'comma' => 'variable',
                default => null,
            },
            'type_args', 'table_args' => $unit === 'close' ? 'sized' : null,
            'table' => $unit === 'open' ? 'table_args' : null,
            'cursor' => match (true) {
                $isWord(...self::SQLSRV_CURSOR_OPTIONS) => 'cursor',
                $isWord('FOR') => 'cursor_for',
                default => null,
            },
            'option' => match (true) {
                $isWord(...self::SQLSRV_SET_SWITCHES) => 'switch',
                $isWord('STATISTICS') => 'statistics',
                $isWord('IDENTITY_INSERT') => 'identity',
                $isWord('TRANSACTION') => 'isolation',
                $isWord(...self::SQLSRV_SET_SETTINGS) => 'setting',
                default => null,
            },
            'statistics', 'switch_next' => $isWord(...self::SQLSRV_SET_SWITCHES) ? 'switch' : null,
            'switch' => match (true) {
                $unit === 'comma' => 'switch_next',
                $isWord('ON', 'OFF') => 'done',
                default => null,
            },
            'identity' => $isName ? 'identity_name' : null,
            'identity_name' => match (true) {
                $unit === 'dot' => 'identity',
                $isWord('ON', 'OFF') => 'done',
                default => null,
            },
            'isolation' => $isWord('ISOLATION') ? 'level' : null,
            'level' => $isWord('LEVEL') ? 'isolation_level' : null,
            'isolation_level' => match (true) {
                $isWord('READ') => 'read',
                $isWord('REPEATABLE') => 'repeatable',
                $isWord('SNAPSHOT', 'SERIALIZABLE') => 'done',
                default => null,
            },
            'read' => $isWord('COMMITTED', 'UNCOMMITTED') ? 'done' : null,
            'repeatable' => $isWord('READ') ? 'done' : null,
            'setting' => $isName || in_array($unit, ['number', 'string', 'variable'], true) ? 'done' : null,
            default => null,
        };
    }

    /**
     * Feed the remaining words of a compound keyword token at $index — for
     * example NOT and EXISTS of "IF NOT EXISTS" — to the expression.
     *
     * @param  array{mode: string, state: string, last: string, top: bool, head?: string, argument?: bool}  $expression
     * @param  list<string>  $words
     * @param  list<Token>  $tokens
     * @return array{mode: string, state: string, last: string, top: bool, head?: string, argument?: bool}|null
     */
    private function sqlsrvFollowWords(array $expression, array $words, array $tokens, int $index): ?array
    {
        foreach ($words as $offset => $word) {
            $isLast = $offset === count($words) - 1;
            $expression = $this->sqlsrvExpressionStep(
                $expression,
                'word',
                $word,
                $words[$offset + 1] ?? $this->sqlsrvNextWord($tokens, $index + 1),
                $isLast && $this->sqlsrvIsCallable($tokens, $index + 1),
            );

            if ($expression === null) {
                return null;
            }
        }

        return $expression;
    }

    /**
     * Move the expression past the depth-0 token at $index, which is not a
     * statement keyword. Returns the new expression — null when the token
     * fits nowhere in it — and the token index and character position
     * before which the next tokens belong to this one: the String of N'...'
     * and the rest of a [bracketed] name.
     *
     * @param  array{mode: string, state: string, last: string, top: bool, head?: string, argument?: bool}  $expression
     * @param  list<Token>  $tokens
     * @param  list<string>  $words
     * @param  array{quoted: list<array{0: int, 1: int}>, comments: list<array{0: int, 1: int}>, brackets: list<array{0: int, 1: int}>}  $ranges
     * @return array{0: array{mode: string, state: string, last: string, top: bool, head?: string, argument?: bool}|null, 1: int, 2: int}
     */
    private function sqlsrvFollowExpression(array $expression, array $tokens, int $index, array $words, array $ranges): array
    {
        $token = $tokens[$index];
        $position = (int) $token->position;

        [$start, $end] = $ranges['brackets'][$this->firstRangeEndingAfter($ranges['brackets'], $position)] ?? [-1, -1];

        if ($position >= $start && $position < $end) {
            $nextIndex = $index + 1;

            while (isset($tokens[$nextIndex]) && (int) $tokens[$nextIndex]->position < $end) {
                $nextIndex++;
            }

            return [$this->sqlsrvExpressionStep($expression, 'name', '', '', $this->sqlsrvIsCallable($tokens, $nextIndex)), -1, $end];
        }

        $next = $tokens[$index + 1] ?? null;

        if ($words === ['N'] && $next !== null && $next->type === TokenType::String
            && (int) $next->position === $position + 1) {
            return [$this->sqlsrvExpressionStep($expression, 'string'), $index + 1, -1];
        }

        if ($words !== []) {
            return [$this->sqlsrvFollowWords($expression, $words, $tokens, $index), -1, -1];
        }

        $unit = match (true) {
            $token->type === TokenType::Number => 'number',
            $token->type === TokenType::String => 'string',
            $token->type === TokenType::Symbol && str_starts_with((string) $token->token, '@') => 'variable',
            $this->isOperator($token, ',') => 'comma',
            $this->isOperator($token, '*') => 'star',
            $this->isOperator($token, '.') => 'dot',
            $this->isOperator($token, '=') => 'equals',
            $token->type === TokenType::Operator => 'operator',
            default => 'name',
        };

        return [$this->sqlsrvExpressionStep($expression, $unit, '', '', $this->sqlsrvIsCallable($tokens, $index + 1)), -1, -1];
    }

    /**
     * The first upper-cased word of the token at $index, "(" for an
     * opening parenthesis, or '' past the end.
     *
     * @param  list<Token>  $tokens
     */
    private function sqlsrvNextWord(array $tokens, int $index): string
    {
        $token = $tokens[$index] ?? null;

        return match (true) {
            $token === null => '',
            $this->isOperator($token, '(') => '(',
            default => $this->firstWord($token),
        };
    }

    /**
     * Whether the token at $index makes the name before it a function or
     * a qualified name: an opening parenthesis or a dot.
     *
     * @param  list<Token>  $tokens
     */
    private function sqlsrvIsCallable(array $tokens, int $index): bool
    {
        $token = $tokens[$index] ?? null;

        return $token !== null && ($this->isOperator($token, '(') || $this->isOperator($token, '.'));
    }

    /**
     * One step of the expression state machine. The state says what the
     * expression waits for: an operand, an operator or clause after a
     * complete operand, an alias name after AS, nothing more after an alias,
     * or only a comma or clause after a closing keyword. Null means the
     * unit fits nowhere — the fail-closed answer for any word that is not
     * on one of the keyword lists, nor a name or alias where one fits.
     *
     * @param  array{mode: string, state: string, last: string, top: bool, head?: string, argument?: bool}  $expression
     * @param  'word'|'name'|'string'|'number'|'variable'|'star'|'comma'|'dot'|'equals'|'operator'|'open'|'close'  $unit
     * @return array{mode: string, state: string, last: string, top: bool, head?: string, argument?: bool}|null
     */
    private function sqlsrvExpressionStep(array $expression, string $unit, string $word = '', string $nextWord = '', bool $isCallable = false): ?array
    {
        $state = $expression['state'];
        $last = $expression['last'];
        $isStatement = $expression['mode'] === 'statement';
        $expression['last'] = $unit === 'word' ? $word : $unit;

        if ($expression['mode'] === 'body') {
            return $this->sqlsrvBodyStep($expression, $unit, $word, $nextWord, $isCallable);
        }

        if (in_array($state, self::SQLSRV_HEADER_STATES, true)
            || ($expression['mode'] === 'declare' && $unit === 'comma' && in_array($state, ['complete', 'closed'], true))) {
            $next = in_array($state, self::SQLSRV_HEADER_STATES, true)
                ? $this->sqlsrvHeaderStep($state, $unit, $word)
                : 'variable';

            if ($next === null) {
                return null;
            }

            $expression['top'] = false;
            $expression['state'] = $next;

            return $expression;
        }

        $unit = in_array($unit, ['dot', 'equals'], true) ? 'operator' : $unit;

        if ($unit === 'word') {
            $isContextual = in_array($last, self::SQLSRV_CONTEXT_KEYWORDS[$word] ?? [], true);

            $next = match (true) {
                $isContextual => in_array($word, ['ROLLUP', 'CUBE'], true) ? 'closed' : 'operand',
                $word === 'ALL' && $last === 'SYSTEM_TIME' => 'closed',
                $word === 'AS' && $nextWord === 'OF' => 'operand',
                $word === 'AS' => $isStatement && $state === 'complete' ? 'as' : null,
                $word === 'WITH' => $state !== 'as' && in_array($nextWord, ['(', 'TIES', 'ROLLUP', 'CUBE'], true) ? 'operand' : null,
                $word === 'TOP' => in_array($last, ['SELECT', 'DISTINCT', 'ALL', 'INSERT', 'UPDATE', 'DELETE', 'MERGE'], true) ? 'operand' : null,
                in_array($word, self::SQLSRV_CLOSING_KEYWORDS, true) => match ($state) {
                    'operand' => 'complete',
                    'complete', 'closed' => 'closed',
                    default => null,
                },
                in_array($word, self::SQLSRV_OPERAND_KEYWORDS, true) => $state === 'operand' ? 'operand' : null,
                in_array($word, self::SQLSRV_CLAUSE_KEYWORDS, true) => match (true) {
                    $state === 'as' => null,
                    $word === 'VALUES' && $last === 'DEFAULT' => 'closed',
                    default => 'operand',
                },
                default => false,
            };

            if ($next !== false) {
                if ($next === null) {
                    return null;
                }

                $expression['top'] = $word === 'TOP';
                $expression['state'] = $next;

                return $expression;
            }

            $unit = 'name';
            $isCallable = $isCallable || in_array($word, self::SQLSRV_VALUE_KEYWORDS, true);
        }

        $next = match ($unit) {
            // Outside a query a bare name can only be a function or a
            // qualified name: anything else is a statement in disguise.
            'name' => match ($state) {
                'operand' => $isStatement || $isCallable ? 'complete' : null,
                'complete' => $isStatement ? 'aliased' : null,
                'as' => 'aliased',
                default => null,
            },
            'string' => match ($state) {
                'operand' => 'complete',
                'complete' => $isStatement ? 'aliased' : null,
                'as' => 'aliased',
                default => null,
            },
            'number', 'variable' => $state === 'operand' ? ($expression['top'] ? 'operand' : 'complete') : null,
            'star' => match ($state) {
                'operand' => 'complete',
                'complete' => 'operand',
                default => null,
            },
            'comma' => in_array($state, ['complete', 'aliased', 'closed'], true) ? 'operand' : null,
            'operator' => in_array($state, ['operand', 'complete'], true) ? 'operand' : null,
            'open' => $state === 'closed' ? null : $state,
            'close' => $expression['top'] ? 'operand' : 'complete',
        };

        if ($next === null) {
            return null;
        }

        if ($unit !== 'open') {
            $expression['top'] = false;
        }

        $expression['state'] = $next;

        return $expression;
    }

    /**
     * The progress of the statement a batch is in: its head keyword, and
     * the clauses it still waits for.
     *
     * @return array{head: string, main: bool, source: bool, set: bool, merge: bool, references: bool}
     */
    private function sqlsrvStatementState(string $head): array
    {
        return [
            'head' => $head,
            // WITH waits for its main query.
            'main' => $head === 'WITH',
            // INSERT waits for VALUES, DEFAULT VALUES, SELECT or EXEC.
            'source' => $head === 'INSERT',
            // UPDATE waits for SET.
            'set' => $head === 'UPDATE',
            'merge' => $head === 'MERGE',
            // A foreign key was declared: ON DELETE / ON UPDATE are its actions.
            'references' => false,
        ];
    }

    /**
     * Move the statement state past the token at $index.
     *
     * @param  array{head: string, main: bool, source: bool, set: bool, merge: bool, references: bool}  $statement
     * @param  list<Token>  $tokens
     * @return array{head: string, main: bool, source: bool, set: bool, merge: bool, references: bool}
     */
    private function sqlsrvAdvanceStatement(array $statement, array $tokens, int $index, string $word): array
    {
        if ($word === 'REFERENCES') {
            $statement['references'] = true;
        }

        if ($statement['main'] && in_array($word, ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'MERGE'], true)) {
            return ['main' => false] + $this->sqlsrvStatementState($word);
        }

        if ($statement['merge'] && $this->sqlsrvPreviousWord($tokens, $index) === 'THEN') {
            $statement['source'] = $word === 'INSERT';
            $statement['set'] = $word === 'UPDATE';

            return $statement;
        }

        if ($statement['source'] && in_array($word, ['VALUES', 'DEFAULT', 'SELECT', 'EXEC', 'EXECUTE'], true)) {
            $statement['source'] = false;
        }

        if ($statement['set'] && ($word === 'SET'
            || ($word === 'STATISTICS' && $this->sqlsrvPreviousWord($tokens, $index) === 'UPDATE'))) {
            $statement['set'] = false;
        }

        return $statement;
    }

    /**
     * Whether the statement keyword at $index belongs to the statement in
     * progress instead of starting a new one.
     *
     * @param  array{head: string, main: bool, source: bool, set: bool, merge: bool, references: bool}  $statement
     * @param  list<Token>  $tokens
     * @param  list<string>  $words
     */
    private function sqlsrvContinuesStatement(array $statement, array $tokens, int $index, array $words): bool
    {
        $word = $words[0];
        $head = $statement['head'];
        $previous = $tokens[$index - 1] ?? null;
        $previousWords = $previous !== null && in_array($previous->type, [TokenType::None, TokenType::Keyword], true)
            ? $this->tokenWords($previous)
            : [];
        $previousFirst = $previousWords[0] ?? '';
        $previousLast = $previousWords === [] ? '' : $previousWords[count($previousWords) - 1];
        $next = $tokens[$index + 1] ?? null;
        $nextWord = $next !== null ? $this->firstWord($next) : '';

        return match (true) {
            $word === 'SELECT' && in_array($previousFirst, ['UNION', 'EXCEPT', 'INTERSECT'], true) => true,
            $statement['source'] && in_array($word, ['SELECT', 'EXEC', 'EXECUTE'], true) => true,
            $statement['main'] && in_array($word, ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'MERGE'], true) => true,
            $statement['merge'] && $previousLast === 'THEN' && in_array($word, ['INSERT', 'UPDATE', 'DELETE'], true) => true,
            $word === 'SET' && $statement['set'] => true,
            $word === 'SET' && $head === 'ALTER' => true,
            $word === 'ROLLBACK' && $head === 'ALTER' && $previousLast === 'WITH' => true,
            $word === 'ALTER' && $head === 'CREATE' && $previousLast === 'OR' => true,
            $word === 'SELECT' && $head === 'CREATE' && $previousLast === 'AS' => true,
            $word === 'SELECT' && $head === 'DECLARE' && $previousLast === 'FOR' => true,
            $word === 'FETCH' && in_array($previousLast, ['ROWS', 'ROW'], true) => true,
            in_array($word, ['DELETE', 'UPDATE'], true) && $previousLast === 'ON' && $statement['references'] => true,
            $word === 'SET' && $statement['references'] && in_array($previousLast, ['DELETE', 'UPDATE'], true)
                && ($previousFirst === 'ON' || $this->sqlsrvPreviousWord($tokens, $index - 1) === 'ON') => true,
            $word === 'IF' && in_array($head, ['DROP', 'ALTER', 'CREATE'], true)
                && in_array($previousLast, self::SQLSRV_OBJECT_TYPES, true)
                && (count($words) > 1 || in_array($nextWord, ['EXISTS', 'NOT'], true)) => true,
            in_array($word, ['DROP', 'ALTER'], true) && $head === 'ALTER'
                && in_array($nextWord, ['COLUMN', 'CONSTRAINT', 'INDEX', 'PERIOD'], true) => true,
            in_array($head, ['GRANT', 'REVOKE', 'DENY'], true)
                && (in_array($previousLast, ['GRANT', 'REVOKE', 'DENY', 'FOR', 'WITH'], true)
                    || ($previous !== null && $this->isOperator($previous, ','))) => true,
            default => false,
        };
    }

    /**
     * The last upper-cased word of the word token before $index, or '' when
     * that token is not a word.
     *
     * @param  list<Token>  $tokens
     */
    private function sqlsrvPreviousWord(array $tokens, int $index): string
    {
        $previous = $tokens[$index - 1] ?? null;

        if ($previous === null || ! in_array($previous->type, [TokenType::None, TokenType::Keyword], true)) {
            return '';
        }

        $words = $this->tokenWords($previous);

        return $words === [] ? '' : $words[count($words) - 1];
    }

    /**
     * Whether the CREATE or ALTER at $index defines a module — a procedure,
     * function, trigger or view, optionally as CREATE OR ALTER. Its body
     * runs later, not now, and the definition is the last statement of its
     * batch.
     *
     * @param  list<Token>  $tokens
     */
    private function sqlsrvDefinesModule(array $tokens, int $index): bool
    {
        $next = $index + 1;

        if (isset($tokens[$next], $tokens[$next + 1]) && $this->firstWord($tokens[$next]) === 'OR'
            && $this->firstWord($tokens[$next + 1]) === 'ALTER') {
            $next += 2;
        }

        return isset($tokens[$next])
            && in_array($this->firstWord($tokens[$next]), ['PROCEDURE', 'PROC', 'FUNCTION', 'TRIGGER', 'VIEW'], true);
    }

    /**
     * EXPLAIN ANALYZE runs the statement it explains, so the statement is
     * judged by that inner statement: it takes the inner type and DDL flag,
     * the inner WHERE rule and denylist apply, and the inner violations are
     * reported as its own. The prepared SQL keeps the EXPLAIN prefix verbatim
     * and only carries the inner statement's own rewrite (the LIMIT of an
     * inner SELECT), so no clause is ever appended to the option block.
     *
     * @param  list<string>  $violations  violations already collected for the outer statement
     * @return array{0: StatementInfo, 1: list<string>}
     */
    private function analyzeExplainAnalyze(
        string $sql,
        int $innerOffset,
        string $keyword,
        int $position,
        ?DbDriver $driver,
        int $depth,
        array $violations,
    ): array {
        $label = "Statement {$position}";
        $innerSql = trim(substr($sql, $innerOffset));

        if ($innerSql === '') {
            $violations[] = "{$label}: {$keyword} ANALYZE without a statement to analyze is not allowed.";

            return [new StatementInfo($sql, $sql, StatementType::Write, false), $violations];
        }

        [$inner, $innerViolations] = $this->analyzeNestedStatement($innerSql, $position, $driver, $depth);

        $prefix = substr($sql, 0, $innerOffset);

        return [
            new StatementInfo(
                $sql,
                $prefix.$inner->preparedSql,
                $inner->type,
                $inner->parsed,
                $inner->limitInjected,
                $inner->limitClamped,
                $inner->isDdl,
            ),
            array_merge($violations, $innerViolations),
        ];
    }

    /**
     * Whether the SET statement whose keyword sits at $keywordIndex is
     * MariaDB's SET STATEMENT ... FOR form.
     *
     * @param  list<Token>  $tokens
     */
    private function isSetStatement(array $tokens, int $keywordIndex): bool
    {
        $next = $tokens[$keywordIndex + 1] ?? null;

        return $next !== null
            && $next->type !== TokenType::Symbol
            && strtoupper((string) $next->value) === 'STATEMENT';
    }

    /**
     * MariaDB's SET STATEMENT a = 1, ... FOR <statement>. The assignment
     * list has already been judged by serverVariableViolations(); the
     * statement after FOR is what actually runs, so it goes through every
     * rule a top-level statement gets (a nested SET STATEMENT included,
     * within MAX_NESTING_DEPTH) and lends the whole its type and LIMIT.
     * A form the guard cannot split that way is refused.
     *
     * @param  list<Token>  $tokens
     * @param  list<string>  $violations
     * @return array{0: StatementInfo, 1: list<string>}
     */
    private function analyzeSetStatement(
        string $sql,
        array $tokens,
        int $wrappingParens,
        int $position,
        ?DbDriver $driver,
        int $depth,
        array $violations,
    ): array {
        $label = "Statement {$position}";

        // The token positions are offsets into the dollar-quote-masked text,
        // which is shorter than $sql once a $tag$...$tag$ string is masked;
        // no dialect that runs SET STATEMENT reads dollar quotes, so such a
        // statement is refused rather than cut at a shifted offset.
        if ($this->maskDollarQuoted($sql) !== $sql) {
            $violations[] = "{$label}: SET STATEMENT with a dollar-quoted string is not allowed.";

            return [new StatementInfo($sql, $sql, StatementType::Write, false), $violations];
        }

        $forIndex = $wrappingParens === 0 ? $this->topLevelForIndex($tokens, 2) : null;
        $body = '';
        $innerOffset = 0;

        if ($forIndex !== null) {
            // "FOR" may open a compound keyword token ("FOR UPDATE"), so the
            // statement starts right after the word itself, not the token.
            $rest = substr($sql, $this->tokenByteOffset($sql, $tokens[$forIndex]->position) + strlen('FOR'));
            $body = trim($rest);
            $innerOffset = strlen($sql) - strlen(ltrim($rest));
        }

        if ($body === '') {
            $violations[] = "{$label}: SET STATEMENT without a statement after a top-level FOR is not allowed.";

            return [new StatementInfo($sql, $sql, StatementType::Write, false), $violations];
        }

        // The assignment values are evaluated too: a state-changing call in
        // one of them makes the whole a write.
        [$functionViolations, $functionWrites] = $this->functionCallEffects(array_slice($tokens, 0, $forIndex));

        foreach ($functionViolations as $message) {
            $violations[] = "{$label}: {$message}";
        }

        [$inner, $innerViolations] = $this->analyzeNestedStatement($body, $position, $driver, $depth);

        return [
            new StatementInfo(
                $sql,
                substr($sql, 0, $innerOffset).$inner->preparedSql,
                $functionWrites ? StatementType::Write : $inner->type,
                $inner->parsed,
                $inner->limitInjected,
                $inner->limitClamped,
                $inner->isDdl,
            ),
            array_merge($violations, $innerViolations),
        ];
    }

    /**
     * Inspect a statement that another statement will execute on its behalf,
     * with exactly the rules a top-level statement gets. The nesting depth is
     * bounded; past it the statement is rejected instead of being read
     * further, so a deep enough wrapper can never fall through as a read.
     *
     * @return array{0: StatementInfo, 1: list<string>}
     */
    private function analyzeNestedStatement(string $sql, int $position, ?DbDriver $driver, int $depth): array
    {
        if ($depth + 1 > self::MAX_NESTING_DEPTH) {
            return [
                new StatementInfo($sql, $sql, StatementType::Write, false),
                ["Statement {$position}: statements nested more than ".self::MAX_NESTING_DEPTH.' levels deep are not allowed.'],
            ];
        }

        return $this->analyzeStatement($sql, $position, $driver, $depth + 1);
    }

    /**
     * The statements a statement runs from text — the rules a top-level
     * statement gets, applied to each of them — and whether it runs one:
     *
     *  - SQL Server (and an unknown driver): EXEC ('...') / EXEC (N'...'),
     *    sp_executesql with a literal statement (bare, after EXEC, or named
     *
     *    @stmt = ...), and the procedures in
     *    {@see self::DYNAMIC_SQL_PROCEDURES}, which are refused outright;
     *  - every driver: EXECUTE IMMEDIATE '...' (MariaDB) and PREPARE ...
     *    FROM '...' (MySQL) / PREPARE ... AS <statement> (PostgreSQL).
     *
     * Only a single, plain string literal is read. A variable, an
     * expression, a concatenation, a literal the dialects read differently
     * (a backslash) and anything after the literal that changes where or as
     * whom it runs (EXEC (...) AT server, EXEC (...) AS USER) is refused:
     * QueryProxy cannot see the statement that would run.
     *
     * @param  list<Token>  $tokens
     * @return array{0: list<string>, 1: bool}
     */
    private function dynamicSqlViolations(string $sql, array $tokens, int $position, ?DbDriver $driver, int $depth): array
    {
        $label = "Statement {$position}";
        $sqlsrv = $driver === null || $driver === DbDriver::Sqlsrv;
        $violations = [];
        $runsDynamicSql = false;

        if ($this->isWordAt($tokens, 0, 'PREPARE')) {
            $runsDynamicSql = true;
            array_push($violations, ...$this->prepareViolations($sql, $tokens, $position, $driver, $depth));
        }

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $exec = $this->isWordAt($tokens, $index, 'EXEC') || $this->isWordAt($tokens, $index, 'EXECUTE');

            if ($exec && $this->isWordAt($tokens, $index + 1, 'IMMEDIATE')) {
                $runsDynamicSql = true;
                [$text, $end] = $this->stringLiteralAt($tokens, $index + 2);

                if ($text === null || (isset($tokens[$end + 1]) && ! $this->isWordAt($tokens, $end + 1, 'USING'))) {
                    $violations[] = "{$label}: EXECUTE IMMEDIATE with a statement QueryProxy cannot read (a variable, an expression or a concatenation) is not allowed.";
                } else {
                    array_push($violations, ...$this->analyzeDynamicSql($text, $position, $driver, $depth));
                }

                continue;
            }

            if (! $sqlsrv) {
                continue;
            }

            $start = $exec ? $this->execTargetAt($tokens, $index) : ($index === 0 ? 0 : null);

            if ($start === null || ! isset($tokens[$start])) {
                continue;
            }

            if ($exec && $this->isOperator($tokens[$start], '(')) {
                $runsDynamicSql = true;
                [$text, $end] = $this->stringLiteralAt($tokens, $start + 1);
                $close = $tokens[$end + 1] ?? null;

                if ($text === null || $close === null || ! $this->isOperator($close, ')') || isset($tokens[$end + 2])) {
                    $violations[] = "{$label}: EXEC (...) with a statement QueryProxy cannot read (a variable, an expression, a concatenation or a remote / impersonated run) is not allowed.";
                } else {
                    array_push($violations, ...$this->analyzeDynamicSql($text, $position, $driver, $depth));
                }

                continue;
            }

            [$procedure, $nameEnd] = $this->multipartNameAt($tokens, $start);

            if ($procedure === 'sp_executesql') {
                $runsDynamicSql = true;
                $argument = $nameEnd + 1;
                $parameter = $tokens[$argument] ?? null;

                if ($parameter !== null && $parameter->type === TokenType::Symbol
                    && strtolower((string) $parameter->token) === '@stmt'
                    && isset($tokens[$argument + 1]) && $this->isOperator($tokens[$argument + 1], '=')) {
                    $argument += 2;
                }

                [$text, $end] = $this->stringLiteralAt($tokens, $argument);
                $next = $tokens[$end + 1] ?? null;

                if ($text === null || ($next !== null && ! $this->isOperator($next, ','))) {
                    $violations[] = "{$label}: sp_executesql with a statement QueryProxy cannot read (a variable, an expression or a concatenation) is not allowed.";
                } else {
                    array_push($violations, ...$this->analyzeDynamicSql($text, $position, $driver, $depth));
                }
            } elseif ($procedure !== null && $this->matchesFunctionList($procedure, self::DYNAMIC_SQL_PROCEDURES)) {
                $runsDynamicSql = true;
                $violations[] = "{$label}: {$procedure} is not allowed through QueryProxy: it runs a statement QueryProxy cannot read.";
            }
        }

        return [array_values(array_unique($violations)), $runsDynamicSql];
    }

    /**
     * The statement a PREPARE stores, judged as if it ran: PostgreSQL's
     * "PREPARE name [(types)] AS <statement>" and MySQL's "PREPARE name FROM
     * '<statement>'". A statement held in a variable (FROM @q) or built by an
     * expression cannot be read and is refused.
     *
     * @param  list<Token>  $tokens
     * @return list<string>
     */
    private function prepareViolations(string $sql, array $tokens, int $position, ?DbDriver $driver, int $depth): array
    {
        $unreadable = ["Statement {$position}: a PREPARE QueryProxy cannot read (a statement held in a variable, an expression or a concatenation) is not allowed."];

        if (! isset($tokens[1])) {
            return $unreadable;
        }

        [$name, $index] = $this->namePartAt($tokens, 1);

        if ($name === null) {
            return $unreadable;
        }

        $index++;

        if (isset($tokens[$index]) && $this->isOperator($tokens[$index], '(')) {
            $close = $this->matchingParenthesisAt($tokens, $index);

            if ($close === null) {
                return $unreadable;
            }

            $index = $close + 1;
        }

        if ($this->isWordAt($tokens, $index, 'AS') && isset($tokens[$index + 1])) {
            $statement = trim(mb_substr($this->maskDollarQuoted($sql), (int) $tokens[$index + 1]->position));

            return $this->analyzeNestedStatement($statement, $position, $driver, $depth)[1];
        }

        if ($this->isWordAt($tokens, $index, 'FROM')) {
            [$text, $end] = $this->stringLiteralAt($tokens, $index + 1);

            if ($text === null || isset($tokens[$end + 1])) {
                return $unreadable;
            }

            return $this->analyzeDynamicSql($text, $position, $driver, $depth);
        }

        return $unreadable;
    }

    /**
     * Judge SQL text that a statement runs (EXEC ('...'), sp_executesql,
     * EXECUTE IMMEDIATE, PREPARE ... FROM) exactly as a top-level request:
     * the same lexical checks, the same statement split and — for SQL
     * Server — the same batch reader, one nesting level deeper. Transaction
     * control is refused inside it: QueryProxy owns the transaction the
     * request runs in.
     *
     * @return list<string>
     */
    private function analyzeDynamicSql(string $text, int $position, ?DbDriver $driver, int $depth): array
    {
        $label = "Statement {$position}";

        if ($depth + 1 > self::MAX_NESTING_DEPTH) {
            return ["{$label}: statements nested more than ".self::MAX_NESTING_DEPTH.' levels deep are not allowed.'];
        }

        [$text, $lexicalViolation] = $this->lexicalView($text, $driver);

        if ($lexicalViolation !== null) {
            return ["{$label}: {$lexicalViolation}"];
        }

        [$skipped, $versionViolation] = $this->skippedCommentReading($text, $driver);

        if ($versionViolation !== null) {
            return ["{$label}: {$versionViolation}"];
        }

        $violations = $this->dynamicSqlReadingViolations($text, $position, $driver, $depth);

        // The server may skip a version-conditional comment in the text
        // (see skippedCommentReading()); that reading has to pass as well.
        if ($violations === [] && $skipped !== null) {
            $violations = array_map(
                fn (string $violation): string => "{$label}: the server skips a version-conditional comment (\"/*!NNNNN ... */\") when its own version is lower, and the SQL without the comment is not allowed: ".(str_starts_with($violation, "{$label}: ") ? substr($violation, strlen("{$label}: ")) : $violation),
                $this->dynamicSqlReadingViolations($skipped, $position, $driver, $depth),
            );
        }

        return $violations;
    }

    /**
     * One reading of the dynamic SQL a statement runs, already through
     * lexicalView(): split, judged statement by statement and checked for
     * transaction control.
     *
     * @return list<string>
     */
    private function dynamicSqlReadingViolations(string $text, int $position, ?DbDriver $driver, int $depth): array
    {
        $label = "Statement {$position}";
        $statements = $this->splitStatements($text);

        if ($statements === []) {
            return [];
        }

        [$executables, $isTransaction, $transactionViolations] = $this->extractTransaction($statements);
        $violations = [];

        foreach ($executables as $statement) {
            [, $statementViolations] = $driver === DbDriver::Sqlsrv
                ? $this->analyzeSqlsrvBatch($statement, $position, $depth + 1)
                : $this->analyzeStatement($statement, $position, $driver, $depth + 1);
            array_push($violations, ...$statementViolations);
        }

        // SQL Server's batch reader refuses transaction control it sees
        // (BEGIN TRAN, ROLLBACK, ...) itself; the message is only added when
        // it has not already said so.
        $readerRefusedTransactionControl = in_array("{$label}: ".self::SQLSRV_TRANSACTION_CONTROL_VIOLATION, $violations, true);

        if (($isTransaction || $transactionViolations !== [] || count($executables) !== count($statements))
            && ! $readerRefusedTransactionControl) {
            array_unshift($violations, "{$label}: transaction control inside dynamic SQL is not allowed: QueryProxy runs the request in its own transaction.");
        }

        return $violations;
    }

    /**
     * The statements a WITH clause runs, judged with the rules a top-level
     * statement gets: a data-modifying CTE body (PostgreSQL's
     * "WITH d AS (DELETE ... RETURNING *)") and the main statement after the
     * clause ("WITH a AS (...) DELETE FROM t"). Each CTE is read as
     * name [(columns)] AS [[NOT] MATERIALIZED] (body) [SEARCH ...] [CYCLE ...];
     * SQL Server's XMLNAMESPACES (...) is skipped. A clause that does not
     * read that way is refused.
     *
     * @param  list<Token>  $tokens
     * @return list<string>
     */
    private function commonTableExpressionViolations(string $sql, array $tokens, int $withIndex, int $position, ?DbDriver $driver, int $depth): array
    {
        $unreadable = ["Statement {$position}: a WITH clause QueryProxy cannot read is not allowed."];
        $masked = $this->maskDollarQuoted($sql);
        $violations = [];
        $index = $withIndex + 1;

        if ($this->isWordAt($tokens, $index, 'RECURSIVE')) {
            $index++;
        }

        while (true) {
            if (! isset($tokens[$index])) {
                return $unreadable;
            }

            if ($this->isWordAt($tokens, $index, 'XMLNAMESPACES') && isset($tokens[$index + 1]) && $this->isOperator($tokens[$index + 1], '(')) {
                $close = $this->matchingParenthesisAt($tokens, $index + 1);

                if ($close === null) {
                    return $unreadable;
                }

                $index = $close + 1;
            } else {
                $body = $this->commonTableExpressionBodyAt($tokens, $index);

                if ($body === null) {
                    return $unreadable;
                }

                [$open, $close] = $body;
                $bodySql = trim(mb_substr(
                    $masked,
                    (int) $tokens[$open]->position + 1,
                    (int) $tokens[$close]->position - (int) $tokens[$open]->position - 1,
                ));

                if ($this->needsNestedInspection($bodySql)) {
                    array_push($violations, ...$this->analyzeNestedStatement($bodySql, $position, $driver, $depth)[1]);
                }

                $index = $this->skipCommonTableExpressionTail($tokens, $close + 1);

                if ($index === null) {
                    return $unreadable;
                }
            }

            if (isset($tokens[$index]) && $this->isOperator($tokens[$index], ',')) {
                $index++;

                continue;
            }

            break;
        }

        if (! isset($tokens[$index])) {
            return $unreadable;
        }

        $mainSql = trim(mb_substr($masked, (int) $tokens[$index]->position));

        if ($this->needsNestedInspection($mainSql)) {
            array_push($violations, ...$this->analyzeNestedStatement($mainSql, $position, $driver, $depth)[1]);
        }

        return $violations;
    }

    /**
     * The opening and closing parenthesis of the body of the CTE whose name
     * starts at $index — name [(columns)] AS [[NOT] MATERIALIZED] (body) —
     * or null when the CTE does not read that way.
     *
     * @param  list<Token>  $tokens
     * @return ?array{0: int, 1: int}
     */
    private function commonTableExpressionBodyAt(array $tokens, int $index): ?array
    {
        [$name, $end] = $this->namePartAt($tokens, $index);

        if ($name === null) {
            return null;
        }

        $index = $end + 1;

        if (isset($tokens[$index]) && $this->isOperator($tokens[$index], '(')) {
            $close = $this->matchingParenthesisAt($tokens, $index);

            if ($close === null) {
                return null;
            }

            $index = $close + 1;
        }

        if (! $this->isWordAt($tokens, $index, 'AS')) {
            return null;
        }

        $index++;

        if ($this->isWordAt($tokens, $index, 'NOT')) {
            $index++;

            if (! $this->isWordAt($tokens, $index, 'MATERIALIZED')) {
                return null;
            }
        }

        if ($this->isWordAt($tokens, $index, 'MATERIALIZED')) {
            $index++;
        }

        if (! isset($tokens[$index]) || ! $this->isOperator($tokens[$index], '(')) {
            return null;
        }

        $close = $this->matchingParenthesisAt($tokens, $index);

        return $close === null ? null : [$index, $close];
    }

    /**
     * The index after PostgreSQL's optional SEARCH ... SET <column> and
     * CYCLE ... USING <column> clauses of a recursive CTE, or null when one
     * of them is never finished.
     *
     * @param  list<Token>  $tokens
     */
    private function skipCommonTableExpressionTail(array $tokens, int $index): ?int
    {
        foreach (['SEARCH' => 'SET', 'CYCLE' => 'USING'] as $clause => $terminator) {
            if (! $this->isWordAt($tokens, $index, $clause)) {
                continue;
            }

            do {
                $index++;

                if (! isset($tokens[$index])) {
                    return null;
                }
            } while (! $this->isWordAt($tokens, $index, $terminator));

            $index += 2;

            if (! isset($tokens[$index - 1])) {
                return null;
            }
        }

        return $index;
    }

    /**
     * Whether a statement read out of a CTE has to be judged as a statement
     * of its own: one that starts with UPDATE or DELETE — the statements
     * whose WHERE guard a WITH clause would otherwise hide — or with a WITH
     * clause of its own (PostgreSQL's "WITH d AS (WITH x AS (...) DELETE
     * FROM t RETURNING *)"). The inner WITH is resolved recursively through
     * analyzeNestedStatement(), so every level is read; past
     * MAX_NESTING_DEPTH the statement is rejected.
     */
    private function needsNestedInspection(string $sql): bool
    {
        [$keyword] = $this->leadingKeyword($this->significantTokens($sql));

        return in_array($keyword, ['UPDATE', 'DELETE', 'WITH'], true);
    }

    /**
     * The text of the single-quoted string literal at $index (N'...'
     * included) and the index of its last token. The text is null when no
     * such literal starts there or when the dialects would read it
     * differently (see unquoteRaw()); a "double-quoted" token is a name in
     * SQL Server and PostgreSQL, never a statement.
     *
     * @param  list<Token>  $tokens
     * @return array{0: ?string, 1: int}
     */
    private function stringLiteralAt(array $tokens, int $index): array
    {
        $token = $tokens[$index] ?? null;

        if ($token !== null && $token->type === TokenType::None && strtoupper((string) $token->token) === 'N') {
            $literal = $tokens[$index + 1] ?? null;

            if ($literal === null || $token->position === null || $literal->position !== $token->position + 1) {
                return [null, $index];
            }

            $index++;
            $token = $literal;
        }

        if ($token === null || $token->type !== TokenType::String
            || ($token->flags & Token::FLAG_STRING_DOUBLE_QUOTES) !== 0
            || ! str_starts_with((string) $token->token, "'")) {
            return [null, $index];
        }

        return [$this->unquoteRaw((string) $token->token), $index];
    }

    /**
     * The index of the parenthesis that closes the one at $open, or null
     * when it is never closed.
     *
     * @param  list<Token>  $tokens
     */
    private function matchingParenthesisAt(array $tokens, int $open): ?int
    {
        $level = 0;

        for ($index = $open, $count = count($tokens); $index < $count; $index++) {
            if ($this->isOperator($tokens[$index], '(')) {
                $level++;
            } elseif ($this->isOperator($tokens[$index], ')')) {
                $level--;

                if ($level === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * The statement's leading keyword, read from the token stream, and how
     * many opening parentheses wrap it: "(SELECT ...)" starts with SELECT
     * inside one parenthesis. A compound keyword token ("IF NOT EXISTS")
     * contributes its first word.
     *
     * @param  list<Token>  $tokens
     * @return array{0: string, 1: int}
     */
    private function leadingKeyword(array $tokens): array
    {
        $index = 0;

        while (isset($tokens[$index]) && $tokens[$index]->type === TokenType::Operator && $tokens[$index]->token === '(') {
            $index++;
        }

        if (! isset($tokens[$index])) {
            return ['', $index];
        }

        return [$this->tokenWords($tokens[$index])[0] ?? '', $index];
    }

    /**
     * The upper-cased words of a token; a compound keyword token such as
     * "INSERT IGNORE" or "IF NOT EXISTS" carries several.
     *
     * @return list<string>
     */
    private function tokenWords(Token $token): array
    {
        return preg_split('/\s+/', strtoupper(trim($token->token)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * True when the driver's EXPLAIN only plans its statement, so the
     * functions it calls never run. PostgreSQL and SQLite plan without
     * executing; MySQL and MariaDB may evaluate subqueries while optimizing
     * (documented for derived tables and constant subqueries), so for them —
     * and for an unknown driver — the calls are judged as if they ran.
     */
    private function explainIsPlanOnly(?DbDriver $driver): bool
    {
        return $driver === DbDriver::Pgsql || $driver === DbDriver::Sqlite;
    }

    /**
     * True when the statement an EXPLAIN explains executes a prepared
     * statement anywhere: EXECUTE p(...) itself, or CREATE TABLE ... AS
     * EXECUTE p(...), which PostgreSQL also explains through
     * ExplainExecuteQuery. Any EXECUTE keyword counts, so no form of it is
     * missed.
     */
    private function explainsExecute(string $innerSql): bool
    {
        foreach ($this->significantTokens($innerSql) as $token) {
            if (in_array($token->type, [TokenType::None, TokenType::Keyword], true)
                && in_array('EXECUTE', $this->tokenWords($token), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the option block of an EXPLAIN / DESCRIBE statement:
     *
     *   EXPLAIN [ANALYZE] [VERBOSE] stmt             (PostgreSQL legacy form)
     *   EXPLAIN (ANALYZE [bool], BUFFERS, ...) stmt  (PostgreSQL option list)
     *   EXPLAIN [ANALYZE] [FORMAT = x] stmt          (MySQL)
     *
     * The option list is read fail-closed: every option must be a bare,
     * unquoted name from EXPLAIN_OPTIONS with at most one plain value, and an
     * ANALYZE value must be one PostgreSQL itself accepts as a boolean. A
     * quoted option name ("analyze"), a U&-escaped one, an unknown option, an
     * empty or unclosed list all leave the block unresolved, and the caller
     * then rejects the statement instead of guessing it only reads.
     *
     * "EXPLAIN (SELECT ...)" is a parenthesised statement, not an option list,
     * and is read as such.
     *
     * The original SQL is lexed (not its dollar-masked form) so the token
     * positions index into it; the option block never contains dollar
     * quoting, and the lexer reads left to right, so anything later in the
     * statement cannot shift the tokens of the block. Those positions count
     * characters, so the inner offset goes through tokenByteOffset() before
     * the caller cuts the SQL with substr().
     *
     * @return array{resolved: bool, analyze: bool, innerOffset: int}
     */
    private function explainOptions(string $sql): array
    {
        $tokens = [];

        foreach ($this->lexTokens($sql) as $token) {
            if (! in_array($token->type, [TokenType::Comment, TokenType::Whitespace, TokenType::Delimiter], true)) {
                $tokens[] = $token;
            }
        }

        $unresolved = ['resolved' => false, 'analyze' => false, 'innerOffset' => strlen($sql)];

        if (! isset($tokens[0]) || ! in_array($this->tokenWords($tokens[0])[0] ?? '', self::EXPLAIN_KEYWORDS, true)) {
            return $unresolved;
        }

        $analyze = false;
        $index = 1;

        if (isset($tokens[1]) && $this->isOperator($tokens[1], '(') && ! $this->opensStatement($tokens[2] ?? null)) {
            $options = [[]];
            $depth = 1;

            for ($index = 2, $count = count($tokens); $index < $count; $index++) {
                $token = $tokens[$index];

                if ($this->isOperator($token, '(')) {
                    $depth++;
                } elseif ($this->isOperator($token, ')')) {
                    if (--$depth === 0) {
                        break;
                    }
                } elseif ($depth === 1 && $this->isOperator($token, ',')) {
                    $options[] = [];

                    continue;
                }

                $options[count($options) - 1][] = $token;
            }

            if ($depth !== 0) {
                return $unresolved;
            }

            $index++;

            foreach ($options as $option) {
                $name = $option[0] ?? null;

                if ($name === null
                    || ! in_array($name->type, [TokenType::None, TokenType::Keyword], true)
                    || ! in_array(strtoupper($name->token), self::EXPLAIN_OPTIONS, true)
                    || count($option) > 2) {
                    return $unresolved;
                }

                $value = $option[1] ?? null;

                if (in_array(strtoupper($name->token), ['ANALYZE', 'ANALYSE'], true)) {
                    $enabled = $value === null ? true : $this->booleanOption($value);

                    if ($enabled === null) {
                        return $unresolved;
                    }

                    $analyze = $analyze || $enabled;
                } elseif ($value !== null && ! in_array($value->type, [TokenType::None, TokenType::Keyword, TokenType::Bool, TokenType::Number, TokenType::String], true)) {
                    return $unresolved;
                }
            }
        } else {
            while (isset($tokens[$index]) && in_array($tokens[$index]->type, [TokenType::None, TokenType::Keyword], true)) {
                $word = strtoupper($tokens[$index]->token);

                if (in_array($word, ['ANALYZE', 'ANALYSE'], true)) {
                    $analyze = true;
                    $index++;
                } elseif (in_array($word, ['VERBOSE', 'EXTENDED', 'PARTITIONS'], true)) {
                    $index++;
                } elseif ($word === 'FORMAT' && isset($tokens[$index + 1]) && $this->isOperator($tokens[$index + 1], '=')) {
                    $index += 3;
                } else {
                    break;
                }
            }
        }

        $innerOffset = isset($tokens[$index])
            ? $this->tokenByteOffset($sql, $tokens[$index]->position)
            : strlen($sql);

        return ['resolved' => true, 'analyze' => $analyze, 'innerOffset' => $innerOffset];
    }

    /**
     * True when the server may read ANALYZE as MariaDB's ANALYZE <statement>,
     * which executes that statement. MariaDB does; so may a server behind
     * the mysql driver (Laravel's mysql driver is the usual way to reach
     * MariaDB) or behind an unknown driver, and those are judged fail-closed
     * as if they were MariaDB. PostgreSQL, SQLite and SQL Server have no
     * ANALYZE that runs a statement.
     */
    private function analyzeMayRunStatement(?DbDriver $driver): bool
    {
        return $driver === null || $driver === DbDriver::Mariadb || $driver === DbDriver::Mysql;
    }

    /**
     * Read a statement that starts with ANALYZE as MariaDB would:
     *
     *   ANALYZE [FORMAT = x] statement                    (runs the statement)
     *   ANALYZE [NO_WRITE_TO_BINLOG | LOCAL] TABLE t ...  (maintenance)
     *
     * Returns the byte offset of the statement ANALYZE runs, null when the
     * ANALYZE is not of that form (ANALYZE TABLE, a bare ANALYZE, an
     * identifier or a PostgreSQL option list after it) and keeps its
     * classification, or a violation when ANALYZE would run something the
     * guard refuses to let it run.
     *
     * MySQL and MariaDB take no table name right after ANALYZE, so a keyword
     * there other than TABLE, NO_WRITE_TO_BINLOG or LOCAL starts a statement;
     * one that MariaDB's ANALYZE does not take (DROP, TRUNCATE, SET, CALL,
     * ...) is rejected rather than ignored. Behind FORMAT = x, whatever
     * follows can only be a statement and is read as one. A bare identifier
     * after ANALYZE (PostgreSQL / SQLite "ANALYZE t", reachable through the
     * unknown driver) keeps its classification.
     *
     * The original SQL is lexed (not its dollar-masked form) so the token
     * positions index into it; they count characters, and the offset goes
     * through tokenByteOffset() before it is returned.
     */
    private function analyzeStatementForm(string $sql, int $wrappingParens): int|string|null
    {
        $tokens = [];

        foreach ($this->lexTokens($sql) as $token) {
            if (! in_array($token->type, [TokenType::Comment, TokenType::Whitespace, TokenType::Delimiter], true)) {
                $tokens[] = $token;
            }
        }

        $index = $wrappingParens + 1;
        $format = false;

        if (isset($tokens[$index]) && $tokens[$index]->type === TokenType::Keyword
            && ($this->tokenWords($tokens[$index])[0] ?? '') === 'FORMAT') {
            if (! isset($tokens[$index + 1], $tokens[$index + 2]) || ! $this->isOperator($tokens[$index + 1], '=')) {
                return 'ANALYZE with a FORMAT option QueryProxy cannot read is not allowed.';
            }

            $format = true;
            $index += 3;
        }

        $next = $tokens[$index] ?? null;

        if ($next === null) {
            return $format ? 'ANALYZE without a statement to analyze is not allowed.' : null;
        }

        $word = $this->tokenWords($next)[0] ?? '';

        if ($this->isOperator($next, '(')) {
            $runsStatement = $this->opensStatement($tokens[$index + 1] ?? null);

            if (! $runsStatement && ! $format) {
                return null;
            }

            $word = $runsStatement ? '(' : '';
        } elseif ($next->type === TokenType::Keyword) {
            if (! $format && in_array($word, ['TABLE', 'NO_WRITE_TO_BINLOG', 'LOCAL'], true)) {
                return null;
            }
        } elseif (! $format) {
            return null;
        }

        if (! in_array($word, self::ANALYZE_STATEMENT_KEYWORDS, true)) {
            return 'ANALYZE runs the statement it analyzes and may only run SELECT, INSERT, REPLACE, UPDATE or DELETE.';
        }

        if ($wrappingParens > 0) {
            return 'ANALYZE of a statement inside parentheses is not allowed.';
        }

        return $this->tokenByteOffset($sql, $next->position);
    }

    /**
     * True when a token after "EXPLAIN (" starts a parenthesised statement
     * rather than an option list.
     */
    private function opensStatement(?Token $token): bool
    {
        if ($token === null) {
            return false;
        }

        if ($this->isOperator($token, '(')) {
            return true;
        }

        return $token->type === TokenType::Keyword
            && in_array($this->tokenWords($token)[0] ?? '', ['SELECT', 'WITH', 'VALUES', 'TABLE'], true);
    }

    private function isOperator(Token $token, string $operator): bool
    {
        return $token->type === TokenType::Operator && $token->token === $operator;
    }

    /**
     * The value of an EXPLAIN boolean option, or null when PostgreSQL would
     * not accept it as one. PostgreSQL's defGetBoolean() takes exactly the
     * integers 0 and 1 and the words true / false / on / off (any case,
     * quoted or not); anything else is an error there and unresolved here.
     * The value is read from the raw token: the lexer's own value applies
     * MySQL backslash escapes that PostgreSQL does not.
     */
    private function booleanOption(Token $token): ?bool
    {
        $raw = $token->token;

        if ($token->type === TokenType::Number) {
            return match ($raw) {
                '1' => true,
                '0' => false,
                default => null,
            };
        }

        if ($token->type === TokenType::String) {
            $raw = $this->unquoteRaw($raw);

            if ($raw === null) {
                return null;
            }
        } elseif (! in_array($token->type, [TokenType::None, TokenType::Keyword, TokenType::Bool], true)) {
            return null;
        }

        return match (strtolower($raw)) {
            'true', 'on' => true,
            'false', 'off' => false,
            default => null,
        };
    }

    /**
     * The text between the quotes of a raw quoted token, with the doubled
     * closing quote undone, or null when it cannot be read the same way by
     * every dialect: a backslash is an escape to MySQL and a literal to
     * PostgreSQL, so a body that holds one is not trusted.
     */
    private function unquoteRaw(string $raw): ?string
    {
        $quote = $raw[0] ?? '';

        if (! in_array($quote, ["'", '"', '`'], true) || strlen($raw) < 2 || $raw[strlen($raw) - 1] !== $quote) {
            return null;
        }

        $body = substr($raw, 1, -1);

        if (str_contains($body, '\\')) {
            return null;
        }

        return str_replace($quote.$quote, $quote, $body);
    }

    /**
     * True when a SELECT writes its rows into a table. Every INTO keyword
     * counts, at any nesting depth and however the statement is spaced or
     * parenthesised ("SELECT*INTO t2", "(SELECT * INTO t2 ...)"), except:
     *
     *  - the INTO of INSERT / REPLACE / MERGE (a data-modifying CTE body,
     *    which the WITH check already classifies as a write);
     *  - INTO @variable, a MySQL session write only;
     *  - INTO OUTFILE / DUMPFILE, rejected by the denylist.
     *
     * Counting INTO inside a subquery over-blocks nothing PostgreSQL accepts:
     * it refuses SELECT ... INTO anywhere but the outermost query.
     *
     * @param  list<Token>  $tokens
     */
    private function selectsIntoTable(array $tokens): bool
    {
        foreach ($tokens as $index => $token) {
            if ($token->type !== TokenType::Keyword) {
                continue;
            }

            $words = $this->tokenWords($token);

            if (($words[0] ?? '') !== 'INTO') {
                continue;
            }

            if ($this->followsInsertVerb($tokens, $index)) {
                continue;
            }

            $target = isset($words[1]) ? $words[1] : (isset($tokens[$index + 1]) ? strtoupper($tokens[$index + 1]->token) : null);

            if ($target === null) {
                continue;
            }

            if (! isset($words[1])
                && $tokens[$index + 1]->type === TokenType::Symbol
                && ($tokens[$index + 1]->flags & Token::FLAG_SYMBOL_VARIABLE) !== 0) {
                continue;
            }

            if (in_array($target, ['OUTFILE', 'DUMPFILE'], true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * True when the INTO at $index belongs to INSERT / REPLACE / MERGE,
     * looking back over the MySQL modifiers that may sit between them.
     *
     * @param  list<Token>  $tokens
     */
    private function followsInsertVerb(array $tokens, int $index): bool
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            if ($tokens[$cursor]->type !== TokenType::Keyword) {
                return false;
            }

            foreach (array_reverse($this->tokenWords($tokens[$cursor])) as $word) {
                if (in_array($word, ['INSERT', 'REPLACE', 'MERGE'], true)) {
                    return true;
                }

                if (! in_array($word, ['LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'IGNORE'], true)) {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * Judge every function call in a statement by the function's effect.
     *
     * A call is a name followed by "(" in the comment-free, dollar-masked
     * token stream, so a name inside a string literal, a comment or a
     * dollar-quoted body never counts, and a name that is not called (a
     * column, an alias) does not either. The name is decoded from its raw
     * spelling — bare, "quoted", `quoted` or a PostgreSQL U&"..." Unicode
     * escape (a UESCAPE clause is rejected before this point, see
     * unicodeEscapeClauseViolation()) — so no spelling hides a listed
     * function. A U& name the guard cannot decode is rejected when called.
     *
     * @param  list<Token>  $tokens
     * @return array{0: list<string>, 1: bool} [violations, makes the statement a write]
     */
    private function functionCallEffects(array $tokens): array
    {
        $blocked = $this->blockedFunctions();
        $stateChanging = $this->stateChangingFunctions();
        $resourceConsuming = $this->resourceConsumingFunctions();

        $violations = [];
        $writes = false;

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            [$name, $end, $readable] = $this->functionNameAt($tokens, $index);
            $next = $tokens[$end + 1] ?? null;
            $isCall = $next !== null && $this->isOperator($next, '(');
            $index = $end;

            if (! $isCall) {
                continue;
            }

            if (! $readable) {
                $violations[] = 'A function name written with Unicode escapes QueryProxy cannot decode is not allowed.';

                continue;
            }

            if ($name === null) {
                continue;
            }

            if ($this->matchesFunctionList($name, $blocked)) {
                $violations[] = "{$name}() is not allowed through QueryProxy: it controls the server or other sessions, opens a channel to another server, or runs SQL given as text.";
            } elseif ($this->matchesFunctionList($name, self::FILE_IO_FUNCTIONS)) {
                $violations[] = "{$name}() is not allowed through QueryProxy: it reads or writes files on the database server.";
            } elseif ($name === 'set_config') {
                $writes = true;
                $violations = array_merge($violations, $this->setConfigViolations($tokens, $end + 2));
            } elseif ($this->matchesFunctionList($name, $stateChanging)
                || $this->matchesFunctionList($name, $resourceConsuming)) {
                $writes = true;
            }
        }

        return [$violations, $writes];
    }

    /**
     * The function name that starts at $index: [name, index of its last
     * token, readable]. A PostgreSQL Unicode identifier U&"..." spans the
     * adjacent tokens U, & and the quoted body (the lexer splits it), plus
     * UESCAPE '<char>' when present; any other name is one token. The name is
     * null when the token cannot name a function, and readable is false only
     * for a U& name whose escapes do not decode.
     *
     * @param  list<Token>  $tokens
     * @return array{0: ?string, 1: int, 2: bool}
     */
    private function functionNameAt(array $tokens, int $index): array
    {
        $token = $tokens[$index];
        $ampersand = $tokens[$index + 1] ?? null;
        $body = $tokens[$index + 2] ?? null;

        $isUnicodeIdentifier = in_array($token->type, [TokenType::None, TokenType::Keyword], true)
            && strtoupper($token->token) === 'U'
            && $ampersand !== null && $this->isOperator($ampersand, '&')
            && $body !== null && $body->type === TokenType::String
            && ($body->flags & Token::FLAG_STRING_DOUBLE_QUOTES) !== 0
            && $ampersand->position === $token->position + 1
            && $body->position === $token->position + 2;

        if (! $isUnicodeIdentifier) {
            return [$this->functionName($token), $index, true];
        }

        $end = $index + 2;
        $escape = '\\';
        $marker = $tokens[$index + 3] ?? null;

        if ($marker !== null
            && in_array($marker->type, [TokenType::None, TokenType::Keyword], true)
            && strtoupper($marker->token) === 'UESCAPE') {
            $escapeToken = $tokens[$index + 4] ?? null;
            $end = $index + 4;

            $escape = $escapeToken !== null
                && $escapeToken->type === TokenType::String
                && ($escapeToken->flags & Token::FLAG_STRING_SINGLE_QUOTES) !== 0
                    ? $this->unicodeEscapeCharacter($escapeToken->token)
                    : null;

            if ($escape === null) {
                return [null, min($end, count($tokens) - 1), false];
            }
        }

        $raw = $body->token;
        $name = strlen($raw) >= 2 && $raw[0] === '"' && $raw[strlen($raw) - 1] === '"'
            ? $this->decodeUnicodeEscapes(str_replace('""', '"', substr($raw, 1, -1)), $escape)
            : null;

        return $name === null ? [null, $end, false] : [strtolower($name), $end, true];
    }

    /**
     * The escape character a UESCAPE '<c>' clause names, or null when
     * PostgreSQL would refuse it: it must be one character that is not a hex
     * digit, "+", a quote or whitespace.
     */
    private function unicodeEscapeCharacter(string $raw): ?string
    {
        $character = strlen($raw) >= 2 && $raw[0] === "'" && $raw[strlen($raw) - 1] === "'"
            ? str_replace("''", "'", substr($raw, 1, -1))
            : '';

        if (strlen($character) !== 1
            || ctype_xdigit($character)
            || ctype_space($character)
            || in_array($character, ['+', "'", '"'], true)) {
            return null;
        }

        return $character;
    }

    /**
     * Decode the escapes of a U&"..." body the way PostgreSQL does: <e>XXXX
     * and <e>+XXXXXX name a code point, <e><e> is the escape character
     * itself. Null when any escape is malformed or names a code point that is
     * not a valid, non-surrogate character.
     */
    private function decodeUnicodeEscapes(string $body, string $escape): ?string
    {
        $decoded = '';
        $length = strlen($body);

        for ($offset = 0; $offset < $length;) {
            if ($body[$offset] !== $escape) {
                $decoded .= $body[$offset++];

                continue;
            }

            if (($body[$offset + 1] ?? '') === $escape) {
                $decoded .= $escape;
                $offset += 2;

                continue;
            }

            [$hex, $digits, $width] = ($body[$offset + 1] ?? '') === '+'
                ? [substr($body, $offset + 2, 6), 6, 8]
                : [substr($body, $offset + 1, 4), 4, 5];

            if (strlen($hex) !== $digits || ! ctype_xdigit($hex)) {
                return null;
            }

            $codePoint = (int) hexdec($hex);

            if ($codePoint === 0 || $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
                return null;
            }

            $character = mb_chr($codePoint, 'UTF-8');

            if ($character === false) {
                return null;
            }

            $decoded .= $character;
            $offset += $width;
        }

        return $decoded;
    }

    /**
     * set_config() is judged like SET: a literal setting name is checked
     * against the dangerous-variable list, and a name the guard cannot read
     * — a column, a concatenation, a subquery — is rejected outright.
     *
     * @param  list<Token>  $tokens
     * @return list<string>
     */
    private function setConfigViolations(array $tokens, int $argumentIndex): array
    {
        $argument = $tokens[$argumentIndex] ?? null;
        $following = $tokens[$argumentIndex + 1] ?? null;

        $isLiteral = $argument !== null
            && $argument->type === TokenType::String
            && ($argument->flags & Token::FLAG_STRING_SINGLE_QUOTES) !== 0
            && $following !== null
            && $following->type === TokenType::Operator
            && in_array($following->token, [',', ')'], true);

        if (! $isLiteral) {
            return ['set_config() with a setting name that is not a plain string literal is not allowed through QueryProxy.'];
        }

        $name = strtolower(trim((string) $argument->value));

        if (in_array($name, $this->dangerousVariables(), true)) {
            return ["set_config('{$name}') is not allowed through QueryProxy: ".$this->dangerousVariableReason($name)];
        }

        return [];
    }

    /**
     * The case-folded function name a token spells, or null when the token
     * cannot name a function: a single-quoted string, a @variable, a number.
     *
     * A quoted name is read from the raw token — quotes stripped, doubled
     * quotes undone — and not from the lexer value, which applies MySQL
     * backslash escapes that neither dialect applies inside an identifier.
     */
    private function functionName(Token $token): ?string
    {
        $isQuoted = match ($token->type) {
            TokenType::None, TokenType::Keyword => false,
            TokenType::String => ($token->flags & Token::FLAG_STRING_DOUBLE_QUOTES) !== 0 ? true : null,
            TokenType::Symbol => ($token->flags & Token::FLAG_SYMBOL_BACKTICK) !== 0 ? true : null,
            default => null,
        };

        if ($isQuoted === null) {
            return null;
        }

        if (! $isQuoted) {
            return $this->identifierValue($token);
        }

        $raw = $token->token;
        $quote = $raw[0] ?? '';

        if (strlen($raw) < 2 || $raw[strlen($raw) - 1] !== $quote) {
            return $this->identifierValue($token);
        }

        return strtolower(str_replace($quote.$quote, $quote, substr($raw, 1, -1)));
    }

    /**
     * Exact, case-folded match against a function list; an entry ending in
     * "*" matches every name with that prefix.
     *
     * @param  list<string>  $list
     */
    private function matchesFunctionList(string $name, array $list): bool
    {
        foreach ($list as $entry) {
            if (str_ends_with($entry, '*')
                ? str_starts_with($name, substr($entry, 0, -1))
                : $name === $entry) {
                return true;
            }
        }

        return false;
    }

    private function classify(?object $statement, string $keyword, string $sql, bool $parsed): StatementType
    {
        // The parser skips a leading word it does not know and parses what
        // follows ("DENY SELECT ON t TO u" is a SelectStatement), so a parsed
        // read counts only when the statement itself starts with a read
        // keyword. A parsed SELECT can still smuggle a write inside a CTE
        // body (PostgreSQL WITH ... AS (INSERT ...)), so WITH is re-checked below.
        if (($statement instanceof SelectStatement
            || $statement instanceof ShowStatement
            || $statement instanceof ExplainStatement)
            && in_array(strtoupper($keyword), self::READ_KEYWORDS, true)) {
            return StatementType::Read;
        }

        if ($keyword === 'WITH') {
            // A CTE wrapping data modification (PostgreSQL) is a write. The
            // write keyword may sit inside the parenthesised CTE body, so the
            // scan covers every nesting depth.
            return $this->hasWriteKeyword($sql)
                ? StatementType::Write
                : StatementType::Read;
        }

        if (! $parsed && in_array($keyword, self::READ_KEYWORDS, true)) {
            return StatementType::Read;
        }

        return StatementType::Write;
    }

    /**
     * Inject or clamp the LIMIT clause of a SELECT statement.
     *
     * The injected clause always starts on a fresh line so a trailing line
     * comment ("-- ..." / "# ...") can never swallow it.
     *
     * @return array{0: string, 1: bool, 2: bool, 3: list<string>} [preparedSql, injected, clamped, violations]
     */
    private function applyLimitGuard(string $sql, ?DbDriver $driver): array
    {
        $defaultLimit = (int) config('queryproxy.select_default_limit', 1000);
        $hardLimit = (int) config('queryproxy.select_hard_limit', 10000);

        $tokens = $this->lexTokens($sql);

        $depth = 0;
        $limitIndex = null;
        $forIndex = null;
        $hasFetch = false;
        $topIndex = null;
        $hasOffset = false;
        $hasSetOperator = false;
        $previous = null;

        foreach ($tokens as $i => $token) {
            if ($token->type === TokenType::Operator) {
                if ($token->token === '(') {
                    $depth++;
                } elseif ($token->token === ')') {
                    $depth--;
                }

                continue;
            }

            if ($depth !== 0 || in_array($token->type, [TokenType::Whitespace, TokenType::Comment, TokenType::String], true)) {
                continue;
            }

            $upper = strtoupper($token->token);
            $afterBracket = $previous !== null && $previous->type === TokenType::None && $previous->token === '[';
            $previous = $token;

            if ($afterBracket) {
                // "[top]", "[offset]", "[union]", ... are bracketed names,
                // never the clause or operator of the same spelling.
                continue;
            }

            if ($upper === 'LIMIT') {
                $limitIndex = $i;
            } elseif ($upper === 'FETCH') {
                $hasFetch = true;
            } elseif ($upper === 'OFFSET') {
                $hasOffset = true;
            } elseif (in_array($this->firstWord($token), ['UNION', 'EXCEPT', 'INTERSECT'], true)) {
                // The lexer types UNION as a keyword but EXCEPT / INTERSECT
                // as plain words, so the token type is not consulted.
                $hasSetOperator = true;
            } elseif ($upper === 'TOP' && $driver === DbDriver::Sqlsrv && $topIndex === null) {
                $topIndex = $i;
            } elseif ($forIndex === null && $token->type === TokenType::Keyword && str_starts_with($upper, 'FOR ')) {
                // Locking clauses lex as compound keywords: "FOR UPDATE", "FOR SHARE", ...
                $forIndex = $i;
            }
        }

        // ANSI FETCH FIRST/NEXT n ROWS ONLY (PostgreSQL, SQL Server, Oracle).
        if ($hasFetch) {
            return $this->clampFetchClause($sql, $hardLimit);
        }

        // T-SQL SELECT TOP n: clamp the count; never append LIMIT.
        if ($topIndex !== null) {
            return $this->clampTopClause($sql, $tokens, $topIndex, $hardLimit);
        }

        if ($limitIndex === null) {
            if ($driver === DbDriver::Sqlsrv) {
                // LIMIT is not valid T-SQL. A plain top-level SELECT gets
                // TOP (<default>) instead. T-SQL refuses TOP next to
                // OFFSET, and TOP before a UNION / EXCEPT / INTERSECT would
                // bound only the first branch and change the result, so
                // those — and CTEs — are left untouched to the
                // executor-level hard cap.
                if ($hasOffset || $hasSetOperator) {
                    return [$sql, false, false, []];
                }

                return $this->injectTopClause($sql, $tokens, $defaultLimit);
            }

            // No top-level LIMIT: inject the default, before a FOR UPDATE/SHARE
            // locking clause when one exists.
            if ($forIndex !== null && $tokens[$forIndex]->position !== null) {
                $pos = $tokens[$forIndex]->position;

                return [rtrim(substr($sql, 0, $pos))."\nLIMIT {$defaultLimit}\n".substr($sql, $pos), true, false, []];
            }

            return [rtrim(rtrim($sql), ';')."\nLIMIT {$defaultLimit}", true, false, []];
        }

        // Collect the LIMIT clause tokens:
        //   LIMIT n | LIMIT offset, n | LIMIT n OFFSET o | LIMIT ALL
        // Anything else (arithmetic, placeholders, ...) is rejected outright:
        // a limit the guard cannot understand is a limit it cannot enforce.
        $clause = [];

        for ($i = $limitIndex + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (in_array($token->type, [TokenType::Whitespace, TokenType::Comment], true)) {
                continue;
            }

            $upper = strtoupper($token->token);

            if ($token->type === TokenType::Delimiter) {
                break;
            }

            if ($token->type === TokenType::Keyword && ! ($clause === [] && $upper === 'ALL')) {
                break;
            }

            $clause[] = $token;

            if (count($clause) > 3) {
                break;
            }
        }

        $rowCountToken = null;

        if (count($clause) === 1 && $clause[0]->type === TokenType::Number) {
            $rowCountToken = $clause[0];
        } elseif (count($clause) === 1 && strtoupper($clause[0]->token) === 'ALL') {
            // PostgreSQL "LIMIT ALL" means unlimited: clamp to the hard cap.
            $rowCountToken = $clause[0];
        } elseif (count($clause) === 3
            && $clause[0]->type === TokenType::Number
            && $clause[1]->type === TokenType::Operator
            && $clause[1]->token === ','
            && $clause[2]->type === TokenType::Number) {
            // MySQL "LIMIT offset, count" form: the second number is the count.
            $rowCountToken = $clause[2];
        }

        if ($rowCountToken === null || $rowCountToken->position === null) {
            return [$sql, false, false, [
                'Unsupported LIMIT clause; use a plain row count (e.g. LIMIT 100).',
            ]];
        }

        $value = strtoupper($rowCountToken->token) === 'ALL' ? PHP_INT_MAX : (int) $rowCountToken->token;

        if ($value <= $hardLimit) {
            return [$sql, false, false, []];
        }

        $prepared = substr($sql, 0, $rowCountToken->position)
            .$hardLimit
            .substr($sql, $rowCountToken->position + strlen($rowCountToken->token));

        return [$prepared, false, true, []];
    }

    /**
     * @return array{0: string, 1: bool, 2: bool, 3: list<string>}
     */
    private function clampFetchClause(string $sql, int $hardLimit): array
    {
        $pattern = '/\bFETCH\s+(?:FIRST|NEXT)\s+(?:(\d+)\s+)?ROWS?\s+(?:ONLY|WITH\s+TIES)\b/i';

        if (! preg_match($pattern, $sql, $matches, PREG_OFFSET_CAPTURE)) {
            return [$sql, false, false, [
                'Unsupported FETCH clause; use FETCH FIRST <n> ROWS ONLY.',
            ]];
        }

        $count = isset($matches[1]) && $matches[1][1] !== -1 ? (int) $matches[1][0] : 1;

        if ($count <= $hardLimit) {
            return [$sql, false, false, []];
        }

        $prepared = substr($sql, 0, $matches[1][1])
            .$hardLimit
            .substr($sql, $matches[1][1] + strlen($matches[1][0]));

        return [$prepared, false, true, []];
    }

    /**
     * Clamp the T-SQL "TOP n" / "TOP (n)" at $topIndex to the hard cap.
     * TOP n PERCENT is rejected — its row count depends on the table size —
     * and so is any count other than a plain integer (a variable, an
     * expression, a decimal): a limit the guard cannot read is a limit it
     * cannot enforce.
     *
     * Token based, so a TOP inside a comment or a string literal is never
     * mistaken for the clause.
     *
     * @param  list<Token>  $tokens  the full lexer stream, whitespace included
     * @return array{0: string, 1: bool, 2: bool, 3: list<string>}
     */
    private function clampTopClause(string $sql, array $tokens, int $topIndex, int $hardLimit): array
    {
        $unsupported = [$sql, false, false, [
            'Unsupported TOP clause; use TOP <n> with a plain row count.',
        ]];

        $next = $this->nextSignificantIndex($tokens, $topIndex);
        $countToken = null;
        $clauseEnd = $next;

        if ($next !== null && $tokens[$next]->type === TokenType::Number) {
            $countToken = $tokens[$next];
        } elseif ($next !== null && $this->isOperator($tokens[$next], '(')) {
            $inner = $this->nextSignificantIndex($tokens, $next);
            $close = $inner === null ? null : $this->nextSignificantIndex($tokens, $inner);

            if ($inner !== null && $close !== null
                && $tokens[$inner]->type === TokenType::Number
                && $this->isOperator($tokens[$close], ')')) {
                $countToken = $tokens[$inner];
                $clauseEnd = $close;
            }
        }

        if ($countToken === null || $countToken->position === null || ! ctype_digit((string) $countToken->token)) {
            return $unsupported;
        }

        $after = $this->nextSignificantIndex($tokens, (int) $clauseEnd);

        if ($after !== null && strtoupper((string) $tokens[$after]->token) === 'PERCENT') {
            return [$sql, false, false, [
                'TOP ... PERCENT is not allowed through QueryProxy; use TOP <n> with a plain row count.',
            ]];
        }

        if ((int) $countToken->token <= $hardLimit) {
            return [$sql, false, false, []];
        }

        $prepared = substr($sql, 0, $countToken->position)
            .$hardLimit
            .substr($sql, $countToken->position + strlen($countToken->token));

        return [$prepared, false, true, []];
    }

    /**
     * Give a top-level T-SQL SELECT without TOP a "TOP (<default>)", after
     * SELECT or after its DISTINCT / ALL quantifier. Anything that does not
     * start with SELECT (a CTE, for one) is returned unchanged.
     *
     * @param  list<Token>  $tokens  the full lexer stream, whitespace included
     * @return array{0: string, 1: bool, 2: bool, 3: list<string>}
     */
    private function injectTopClause(string $sql, array $tokens, int $defaultLimit): array
    {
        $select = $this->nextSignificantIndex($tokens, -1);

        if ($select === null || strtoupper((string) $tokens[$select]->token) !== 'SELECT') {
            return [$sql, false, false, []];
        }

        $anchor = $select;
        $quantifier = $this->nextSignificantIndex($tokens, $select);

        if ($quantifier !== null && in_array(strtoupper((string) $tokens[$quantifier]->token), ['DISTINCT', 'ALL'], true)) {
            $anchor = $quantifier;
        }

        $position = $tokens[$anchor]->position;

        if ($position === null) {
            return [$sql, false, false, []];
        }

        $offset = $position + strlen((string) $tokens[$anchor]->token);
        $prepared = substr($sql, 0, $offset)." TOP ({$defaultLimit})".substr($sql, $offset);

        return [$prepared, true, false, []];
    }

    /**
     * The index of the first token after $index that is neither whitespace
     * nor a comment, or null at the end of the stream.
     *
     * @param  list<Token>  $tokens
     */
    private function nextSignificantIndex(array $tokens, int $index): ?int
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            if (! in_array($tokens[$i]->type, [TokenType::Whitespace, TokenType::Comment], true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Run the targeted rules — the ones that judge a statement by the
     * dangerous part inside it rather than by its syntax.
     *
     * @return list<string>
     */
    private function targetedViolations(string $sql, ?DbDriver $driver): array
    {
        $tokens = $this->significantTokens($sql);

        if ($tokens === []) {
            return [];
        }

        $keyword = strtoupper((string) $tokens[0]->value);

        if ($keyword === 'DO') {
            return array_values(array_unique(array_merge(
                $this->routineClauseViolations($sql),
                $this->anonymousBlockViolations($sql),
            )));
        }

        if ($keyword === 'SET') {
            return $this->serverVariableViolations($tokens, $driver);
        }

        if ($keyword === 'CREATE') {
            $violations = isset($tokens[1]) && strtoupper((string) $tokens[1]->value) === 'EXTENSION'
                ? $this->extensionViolations($tokens)
                : [];

            // A function or procedure body written as a '...' literal is run
            // by PostgreSQL when the routine is called; it is held to the
            // rules of a DO body (see nestedBodyViolations()).
            if ($driver === null || $driver === DbDriver::Pgsql) {
                $violations = array_merge($violations, $this->nestedBodyViolations($sql, 0));
            }

            if ($driver === DbDriver::Pgsql) {
                $violations = array_merge($violations, $this->routineBodyViolations($sql), $this->routineClauseViolations($sql));
            }

            return array_values(array_unique($violations));
        }

        // ALTER FUNCTION | PROCEDURE | ROUTINE | DATABASE | ROLE | USER ...
        // SET carries a setting into every later call or session.
        if ($keyword === 'ALTER' && $driver === DbDriver::Pgsql) {
            return $this->routineClauseViolations($sql);
        }

        return [];
    }

    /**
     * SQLite PRAGMA is judged against an allowlist of read-only pragmas, and
     * a pragma written with a value is rejected even when its name is
     * allowed: "PRAGMA x = v" always, "PRAGMA x(v)" unless x is one of the
     * hard-coded read pragmas, whose argument names what to report on. A
     * schema prefix ("PRAGMA main.table_info(t)") is read past.
     *
     * @param  list<Token>  $tokens  significant tokens
     * @param  int  $pragmaIndex  index of the PRAGMA word
     */
    private function pragmaViolation(array $tokens, int $pragmaIndex): ?string
    {
        [$name, $end] = $this->multipartNameAt($tokens, $pragmaIndex + 1);

        if ($name === null || $name === '') {
            return 'PRAGMA without a readable pragma name is not allowed through QueryProxy.';
        }

        for ($i = $end + 1, $count = count($tokens); $i < $count; $i++) {
            if ($this->isOperator($tokens[$i], '=')) {
                return "PRAGMA {$name} = ... is not allowed through QueryProxy: setting a pragma changes the database or the connection.";
            }
        }

        if (! in_array($name, $this->allowedPragmas(), true)) {
            return "PRAGMA {$name} is not allowed through QueryProxy: only read-only schema pragmas are.";
        }

        $argument = $tokens[$end + 1] ?? null;

        if ($argument !== null && $this->isOperator($argument, '(')
            && ! in_array($name, self::SQLITE_ALLOWED_PRAGMAS, true)) {
            return "PRAGMA {$name}(...) is not allowed through QueryProxy: the call form sets the pragma; use the bare read form.";
        }

        return null;
    }

    /**
     * The SQL Server and SQLite constructs that reach the operating system,
     * another server or another database file:
     *
     *  - an invoked system procedure from {@see self::BLOCKED_PROCEDURES},
     *    and an EXEC whose target is not a plain or dotted name (a variable,
     *    a string literal, N'...', an expression) — fail closed;
     *  - BULK INSERT (SQL Server and an unknown driver);
     *  - a remote rowset function and load_extension();
     *  - ATTACH / DETACH, VACUUM ... INTO and PRAGMA (allowlisted) — SQLite
     *    and an unknown driver only: the words are ordinary identifiers in
     *    the other dialects.
     *
     * Every token position is scanned, not just the statement head: the
     * statement may sit behind EXPLAIN [QUERY PLAN], inside IF / BEGIN TRY,
     * or follow another statement of a T-SQL batch without a semicolon. A
     * command word counts as an identifier only when the token before it
     * proves an identifier position (see isIdentifierPosition()); anywhere
     * else it is a command, whatever follows it.
     *
     * The scan reads the comment-free token stream, so a name inside a string
     * literal or a comment never counts.
     *
     * @param  list<Token>  $tokens
     * @return list<string>
     */
    private function dialectViolations(array $tokens, ?DbDriver $driver): array
    {
        $sqlite = $driver === null || $driver === DbDriver::Sqlite;
        $sqlsrv = $driver === null || $driver === DbDriver::Sqlsrv;
        $procedures = $this->blockedProcedures();
        $violations = [];

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $exec = $this->isWordAt($tokens, $index, 'EXEC') || $this->isWordAt($tokens, $index, 'EXECUTE');
            $start = $exec ? $this->execTargetAt($tokens, $index) : ($index === 0 ? 0 : null);

            if ($start !== null) {
                [$procedure] = $this->multipartNameAt($tokens, $start);

                if ($procedure !== null && $this->matchesFunctionList($procedure, $procedures)) {
                    $violations[] = "{$procedure} is not allowed through QueryProxy: it runs operating-system commands, changes the server configuration or reaches another server.";
                } elseif ($exec && ! $this->isOpaqueExecTarget($tokens[$start] ?? null) && ! $this->isPlainNameAt($tokens, $start)) {
                    $violations[] = 'EXEC with a module QueryProxy cannot read (a variable, a string or an incomplete name) is not allowed through QueryProxy.';
                }
            }

            if ($sqlsrv && $this->isWordAt($tokens, $index, 'BULK')
                && ($this->isCommandWordAt($tokens, $index, 'BULK')
                    || (isset($tokens[$index + 1]) && $this->firstWord($tokens[$index + 1]) === 'INSERT'))) {
                $violations[] = 'BULK INSERT is not allowed through QueryProxy (server-side file IO).';
            }

            if ($sqlite) {
                array_push($violations, ...$this->sqliteCommandViolations($tokens, $index));
            }

            [$name, $end] = $this->multipartNameAt($tokens, $index);
            $next = $tokens[$end + 1] ?? null;

            if ($name === null || $next === null || ! $this->isOperator($next, '(')) {
                continue;
            }

            if (in_array($name, self::REMOTE_ROWSET_FUNCTIONS, true)) {
                $violations[] = "{$name}() is not allowed through QueryProxy: it reads from another server or from a file on the database server.";
            } elseif (in_array($name, self::CODE_LOADING_FUNCTIONS, true)) {
                $violations[] = "{$name}() is not allowed through QueryProxy: it loads native code into the database process.";
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * The SQLite command at $index, when the word there is ATTACH, DETACH,
     * VACUUM or PRAGMA in a command position. VACUUM is refused when INTO
     * appears anywhere after it in the statement — the schema name before
     * INTO may be bare, [bracketed], "quoted" or qualified.
     *
     * @param  list<Token>  $tokens
     * @return list<string>
     */
    private function sqliteCommandViolations(array $tokens, int $index): array
    {
        if ($this->isCommandWordAt($tokens, $index, 'ATTACH') || $this->isCommandWordAt($tokens, $index, 'DETACH')) {
            return ['ATTACH / DETACH is not allowed through QueryProxy (it changes the database files a connection can reach).'];
        }

        if ($this->isCommandWordAt($tokens, $index, 'VACUUM')) {
            foreach (array_slice($tokens, $index + 1) as $token) {
                if (preg_match('/\bINTO\b/i', (string) $token->token)) {
                    return ['VACUUM INTO is not allowed through QueryProxy (it writes a database file on the server).'];
                }
            }

            return [];
        }

        if ($this->isCommandWordAt($tokens, $index, 'PRAGMA')) {
            $violation = $this->pragmaViolation($tokens, $index);

            return $violation === null ? [] : [$violation];
        }

        return [];
    }

    /**
     * Where the module name of the EXEC / EXECUTE at $index starts, past an
     * "@status =" return-value capture.
     *
     * @param  list<Token>  $tokens
     */
    private function execTargetAt(array $tokens, int $index): int
    {
        $variable = $tokens[$index + 1] ?? null;
        $assignment = $tokens[$index + 2] ?? null;

        if ($variable !== null && $assignment !== null
            && $variable->type === TokenType::Symbol && str_starts_with((string) $variable->token, '@')
            && $this->isOperator($assignment, '=')) {
            return $index + 3;
        }

        return $index + 1;
    }

    /**
     * An EXEC target this phase deliberately leaves to the dynamic-SQL rules
     * or that is not a module at all: "EXEC (...)" / "EXEC('...')" runs a
     * string (judged by the dynamic-SQL guard), and EXECUTE as a privilege
     * in a GRANT list ("GRANT SELECT, EXECUTE ON ...") is followed by "," or
     * ")". Every other unreadable target fails closed.
     */
    private function isOpaqueExecTarget(?Token $target): bool
    {
        return $target !== null
            && ($this->isOperator($target, '(') || $this->isOperator($target, ',') || $this->isOperator($target, ')'));
    }

    /**
     * Whether a plain module name starts at $index: bare or [bracketed]
     * parts joined by dots (master..xp_cmdshell, [dbo].[proc]). A string
     * literal, a "quoted" or `quoted` name, a variable and an expression are
     * not one. Neither is a name glued to the string after it: the lexer
     * reads N'xp_cmdshell' as the word N followed by a string, and "N" with
     * a string after it is refused in either spelling.
     *
     * @param  list<Token>  $tokens
     */
    private function isPlainNameAt(array $tokens, int $index): bool
    {
        $i = $index;
        $last = null;

        while (isset($tokens[$i])) {
            $token = $tokens[$i];

            if ($token->type === TokenType::None && $token->token === '[') {
                $inner = $tokens[$i + 1] ?? null;
                $close = $tokens[$i + 2] ?? null;

                if ($inner === null || $close === null || $close->type !== TokenType::None || $close->token !== ']'
                    || ! in_array($inner->type, [TokenType::None, TokenType::Keyword], true)) {
                    return false;
                }

                $last = $close;
                $i += 3;
            } elseif (in_array($token->type, [TokenType::None, TokenType::Keyword], true) && $token->token !== ']') {
                $last = $token;
                $i++;
            } else {
                return false;
            }

            if (! isset($tokens[$i]) || ! $this->isOperator($tokens[$i], '.')) {
                break;
            }

            while (isset($tokens[$i]) && $this->isOperator($tokens[$i], '.')) {
                $i++;
            }
        }

        $next = $tokens[$i] ?? null;

        if ($last === null || $next === null || $next->type !== TokenType::String) {
            return $last !== null;
        }

        $glued = $last->position !== null && $next->position !== null
            && $next->position === $last->position + strlen((string) $last->token);

        return ! $glued && ! ($i === $index + 1 && strtoupper((string) $last->token) === 'N');
    }

    /**
     * Whether the token at $index is the bare word $word: not quoted, not
     * [bracketed] and not the column part of a qualified name.
     *
     * @param  list<Token>  $tokens
     */
    private function isWordAt(array $tokens, int $index, string $word): bool
    {
        $token = $tokens[$index] ?? null;

        if ($token === null
            || ! in_array($token->type, [TokenType::None, TokenType::Keyword], true)
            || $this->firstWord($token) !== $word) {
            return false;
        }

        $previous = $tokens[$index - 1] ?? null;

        return $previous === null
            || ! ($this->isOperator($previous, '.') || ($previous->type === TokenType::None && $previous->token === '['));
    }

    /**
     * Whether the bare word $word at $index is a command. It is one unless
     * the token before it proves an identifier position; what follows the
     * word is never consulted, because a unary operator, a string or INTO
     * after a command word reads the same as a column in an expression.
     * "SELECT attach FROM t" and "CREATE TABLE t (attach int)" are
     * identifiers; "ATTACH +'x' AS y", "EXPLAIN PRAGMA x" and
     * "SELECT 1 BULK INSERT ..." are commands.
     *
     * @param  list<Token>  $tokens
     */
    private function isCommandWordAt(array $tokens, int $index, string $word): bool
    {
        return $this->isWordAt($tokens, $index, $word) && ! $this->isIdentifierPosition($tokens, $index);
    }

    /**
     * Whether the token before $index can only be followed by an identifier
     * or an expression, never by a new statement: a clause keyword such as
     * SELECT, FROM, JOIN, WHERE, SET or TABLE, a comma, an opening
     * parenthesis or a comparison. Everything else — the statement head,
     * EXPLAIN, BEGIN, THEN, ELSE, AS, a closing parenthesis, a value, an
     * arithmetic operator — leaves the position ambiguous, and an ambiguous
     * position is a command (fail closed).
     *
     * @param  list<Token>  $tokens
     */
    private function isIdentifierPosition(array $tokens, int $index): bool
    {
        $previous = $tokens[$index - 1] ?? null;

        if ($previous === null) {
            return false;
        }

        if ($previous->type === TokenType::Operator) {
            return in_array($previous->token, self::IDENTIFIER_OPERATORS, true);
        }

        if ($previous->type !== TokenType::Keyword) {
            return false;
        }

        $words = preg_split('/\s+/', strtoupper(trim((string) $previous->token))) ?: [];

        return in_array(end($words), self::IDENTIFIER_KEYWORDS, true);
    }

    /**
     * Keywords (the last word of a compound one such as "LEFT JOIN" or
     * "INSERT INTO") after which only an identifier or an expression can
     * follow.
     */
    private const IDENTIFIER_KEYWORDS = [
        'SELECT', 'DISTINCT', 'ALL', 'FROM', 'JOIN', 'BY', 'WHERE', 'HAVING', 'AND', 'OR',
        'ON', 'SET', 'TABLE', 'INDEX', 'VIEW', 'INTO', 'UPDATE', 'IN', 'IS', 'NOT',
        'EXISTS', 'WHEN', 'CASE', 'LIKE', 'BETWEEN', 'VALUES', 'REFERENCES',
    ];

    /**
     * Operators after which only an identifier or an expression can follow.
     */
    private const IDENTIFIER_OPERATORS = [',', '(', '=', '<', '>', '<=', '>=', '<>', '!=', '||'];

    /**
     * The upper-cased first word of a token; compound keywords such as
     * "INSERT INTO" or "UNION ALL" lex as one token.
     */
    private function firstWord(Token $token): string
    {
        return strtoupper((string) strtok((string) $token->token, " \t\r\n"));
    }

    /**
     * The last part of the possibly qualified name that starts at $index —
     * master..xp_cmdshell, [master].[dbo].[xp_cmdshell], main.table_info —
     * case-folded, and the index of its last token. The name is null when
     * the token there cannot name an object.
     *
     * @param  list<Token>  $tokens
     * @return array{0: ?string, 1: int}
     */
    private function multipartNameAt(array $tokens, int $index): array
    {
        $name = null;
        $end = $index;
        $i = $index;

        while (isset($tokens[$i])) {
            [$part, $partEnd] = $this->namePartAt($tokens, $i);

            if ($part === null) {
                break;
            }

            $name = $part;
            $end = $partEnd;
            $i = $partEnd + 1;

            // "master..xp_cmdshell" leaves the schema part empty.
            $dots = $i;

            while (isset($tokens[$dots]) && $this->isOperator($tokens[$dots], '.')) {
                $dots++;
            }

            if ($dots === $i) {
                break;
            }

            $i = $dots;
        }

        return [$name, $end];
    }

    /**
     * One part of a qualified name: a bare, "quoted" or `quoted` identifier
     * (see functionName()), or a SQL Server [bracketed] one, which the lexer
     * splits into "[", the name and "]".
     *
     * @param  list<Token>  $tokens
     * @return array{0: ?string, 1: int}
     */
    private function namePartAt(array $tokens, int $index): array
    {
        $token = $tokens[$index];

        if ($token->type === TokenType::None && $token->token === '[') {
            $inner = $tokens[$index + 1] ?? null;
            $close = $tokens[$index + 2] ?? null;

            if ($inner === null || $close === null || $close->token !== ']') {
                return [null, $index];
            }

            return [$this->functionName($inner), $index + 2];
        }

        if ($token->type === TokenType::None && $token->token === ']') {
            return [null, $index];
        }

        return [$this->functionName($token), $index];
    }

    /**
     * CREATE EXTENSION is judged by the extension being installed. Only the
     * untrusted procedural languages are refused; every other extension —
     * pg_stat_statements, pgcrypto, uuid-ossp, postgis — is ordinary DBA work.
     *
     * The name is read from the token value rather than from the normalized
     * text on purpose: the lexer reports a double-quoted PostgreSQL identifier
     * as a string, so normalize() blanks CREATE EXTENSION "uuid-ossp" down to
     * CREATE EXTENSION '?' and the name would be unreadable — which would also
     * have made CREATE EXTENSION "plpythonu" unreadable.
     *
     * @param  list<Token>  $tokens
     * @return list<string>
     */
    private function extensionViolations(array $tokens): array
    {
        $name = null;

        for ($i = 2, $count = count($tokens); $i < $count; $i++) {
            // "IF NOT EXISTS" lexes as one keyword, or as three when comments
            // are wedged between the words.
            if (in_array(strtoupper((string) $tokens[$i]->value), ['IF', 'NOT', 'EXISTS', 'IF NOT EXISTS'], true)) {
                continue;
            }

            $name = $this->identifierValue($tokens[$i]);

            break;
        }

        if ($name === null || $name === '') {
            return ['CREATE EXTENSION with an unreadable extension name is not allowed through QueryProxy.'];
        }

        if ($this->isUntrustedLanguage($name)) {
            return ["CREATE EXTENSION {$name} is not allowed through QueryProxy: {$name} is an untrusted procedural language, so any user who can call it runs code as the database superuser."];
        }

        return [];
    }

    /**
     * True for a procedural language that runs outside the database's
     * permission system, by name or by naming convention.
     *
     * PostgreSQL marks an untrusted language with a trailing "u" on a "pl"
     * prefix, and that convention is honoured here so a language nobody has
     * listed yet — plpython4u, a new plXu binding — is refused on sight. The
     * trusted languages do not collide with it: plpgsql, pltcl, plperl, pllua
     * and plv8 all end in something else, and their untrusted twins (pltclu,
     * plperlu, plluau) are precisely the ones the convention is meant to name.
     */
    private function isUntrustedLanguage(string $name): bool
    {
        return in_array($name, $this->untrustedLanguages(), true)
            || preg_match('/^pl[a-z0-9_]*u$/i', $name) === 1;
    }

    /**
     * The literals of a DO block: a language written as a literal is
     * refused, and so is more than one literal; the one body literal is then
     * scanned, so that a DO block cannot carry a statement the unconditional
     * denylist refuses on its own. The LANGUAGE clause itself is judged by
     * routineClauseViolations().
     *
     * @return list<string>
     */
    private function anonymousBlockViolations(string $sql): array
    {
        $violations = [];

        $ranges = $this->postgresLexicalRanges($sql);

        if ($ranges === null) {
            $violations[] = 'A DO block that leaves a block comment open is not allowed.';

            return $violations;
        }

        $literals = $this->anonymousBlockLiterals($sql, $ranges);

        if ($this->languageLiteral($sql, $literals, $ranges['comments'])) {
            $violations[] = 'A DO block that names its language with a string literal (LANGUAGE \'...\' or LANGUAGE $$...$$) is not allowed: the guard reads the language, and tells it from the body, only when it is written as a plain name.';

            return $violations;
        }

        if (count($literals) > 1) {
            $violations[] = 'A DO block with more than one string literal is not allowed: PostgreSQL joins literals split across lines into one body, and the guard will not guess which text it runs.';

            return $violations;
        }

        if ($literals === []) {
            return $violations;
        }

        $body = $this->anonymousBlockBody($sql, $literals[0]);

        return array_values(array_unique(array_merge($violations, $this->blockBodyViolations($body, 'DO block'))));
    }

    /**
     * Scan the source text of a procedural body — a DO block's, or a
     * function's written as a '...' literal — with the rules a DO body is
     * held to. $label names the body in the messages ("DO block",
     * "function"); $depth counts the bodies this one is written inside.
     *
     * The body is PL/pgSQL (or SQL), read by PostgreSQL's scanner, so the
     * scan reads it through that scanner's eyes rather than the MySQL-first
     * lexer's (see postgresBlockBodyView()). It applies, in turn:
     *  - the refusal of UESCAPE and of dynamic SQL (EXECUTE);
     *  - the unconditional denylist (forbiddenInBlockBody());
     *  - the top level's blocked-function and guarded SET / set_config()
     *    rules (blockBodyCallViolations());
     *  - the same scan, recursively, on every function body and DO body
     *    written inside this one as a '...' literal (nestedBodyViolations()).
     *
     * @return list<string>
     */
    private function blockBodyViolations(string $body, string $label, int $depth = 0): array
    {
        $view = $this->postgresBlockBodyView($body);

        if ($view === null) {
            $subject = $label === 'DO block' ? 'A DO block whose body' : "A {$label} body that";

            return ["{$subject} leaves a block comment open, or nests dollar quoting more than ".self::MAX_BLOCK_BODY_QUOTE_DEPTH.' levels deep, is not allowed.'];
        }

        // EXECUTE runs nested dollar-quoted strings joined together, and a
        // quote one of them leaves open carries into the next. The body is
        // therefore read a second time with every dollar tag blanked, as one
        // text, which is how the lexer read it before the scan moved to
        // PostgreSQL's rules: "EXECUTE $a$SELECT '$a$ || $b$', pg_read_file(..)
        // --'$b$" hides the call from the first reading, not from this one.
        $flatBody = $this->withoutDollarTags($body);
        $flatView = $this->postgresBlockBodyView($flatBody, openCommentRunsToEnd: true) ?? '';

        // UESCAPE takes its escape character from any string constant —
        // E'..', $$..$$, a continued '..' — so, as at the top level (see
        // unicodeEscapeClauseViolation()), it is refused rather than decoded.
        foreach ([$view, $flatView] as $reading) {
            if (preg_match('/(?<![A-Za-z0-9_$\x80-\xff])UESCAPE(?![A-Za-z0-9_$\x80-\xff])/i', $reading) === 1) {
                return ["Inside the {$label} body: a UESCAPE clause is not allowed through QueryProxy; write Unicode escapes with the default backslash escape."];
            }
        }

        $violations = [];

        // Dynamic SQL is refused outright: the statement EXECUTE runs is text
        // built at run time, which no reading of the body can see. Both
        // readings are checked, so a comment, a line break, a U&"..." or a
        // "..." spelling of the keyword does not hide it, nor does a function
        // body written in a nested dollar-quoted string.
        if ($this->executesDynamicSql($view) || $this->executesDynamicSql($flatView)) {
            $violations[] = "Inside the {$label} body: dynamic SQL (EXECUTE) is not allowed through QueryProxy, because the statement it runs is built at run time and cannot be inspected.";
        }

        // The call and SET readings keep a string whose value is a plain
        // setting name, so set_config('<name>', ...) is judged by its name;
        // nested dollar-quoted text is read as code, as it is for EXECUTE.
        $nameView = $this->postgresBlockBodyView($body, keepNameStrings: true) ?? '';
        $flatNameView = $this->postgresBlockBodyView($flatBody, openCommentRunsToEnd: true, keepNameStrings: true) ?? '';

        return array_values(array_unique(array_merge(
            $violations,
            $this->forbiddenInBlockBody($view, $label),
            $this->forbiddenInBlockBody($flatView, $label),
            $this->blockBodyCallViolations($this->withoutDollarTags($nameView), $label),
            $this->blockBodyCallViolations($flatNameView, $label),
            $this->nestedBodyViolations($body, $depth),
        )));
    }

    /**
     * Hold one reading of a procedural body (see blockBodyViolations()) to
     * the rules the top level applies to a statement's calls and SETs, by
     * running the same checks: functionCallEffects() — BLOCKED_FUNCTIONS,
     * file IO, set_config() — over the whole reading, and
     * serverVariableViolations() over every body statement that starts with
     * SET. A body statement starts after a semicolon or after a PL/pgSQL
     * keyword that opens one (BEGIN, THEN, ELSE, LOOP, ...).
     *
     * The reading has its comments, strings and dollar tags rewritten
     * already; "`" and "@" are blanked too, because they are operator
     * characters to PostgreSQL but quote an identifier or a variable to the
     * lexer, which would then hide the name after them.
     *
     * @return list<string>
     */
    private function blockBodyCallViolations(string $reading, string $label): array
    {
        $reading = strtr($reading, ['`' => ' ', '@' => ' ']);
        [$messages] = $this->functionCallEffects($this->significantTokens($reading));

        $statements = preg_split('/;|\b(?:BEGIN|DECLARE|END|THEN|ELSE|ELSIF|LOOP|EXECUTE|PERFORM|RETURN)\b/i', $reading) ?: [];

        foreach ($statements as $statement) {
            $tokens = $this->significantTokens($statement);

            if ($tokens !== [] && strtoupper((string) $tokens[0]->value) === 'SET') {
                $messages = array_merge($messages, $this->serverVariableViolations($tokens, DbDriver::Pgsql));
            }
        }

        return array_map(fn (string $message): string => "Inside the {$label} body: {$message}", $messages);
    }

    /**
     * Scan every procedural body written inside $text: a function or
     * procedure body (CREATE [OR REPLACE] FUNCTION | PROCEDURE ... AS '...'
     * or AS $tag$...$tag$) and a nested DO block's body (DO '...'). Inside a
     * DO body a '...' body reads as data, while PostgreSQL runs it when the
     * function is called; at the top level any function body does. Other
     * nested dollar-quoted text is searched as well, since a body there may
     * hold one in turn.
     *
     * A body the guard cannot read the way PostgreSQL does is refused: one
     * left unterminated, and a '...' one followed by another literal, which
     * PostgreSQL joins to it when a line break separates them.
     *
     * @return list<string>
     */
    private function nestedBodyViolations(string $text, int $depth): array
    {
        if ($depth >= self::MAX_BLOCK_BODY_QUOTE_DEPTH) {
            return ['A procedural body nested more than '.self::MAX_BLOCK_BODY_QUOTE_DEPTH.' levels deep is not allowed.'];
        }

        $ranges = $this->postgresLexicalRanges($text, openCommentRunsToEnd: true);

        if ($ranges === null) {
            return ['A function body the guard cannot read is not allowed.'];
        }

        $violations = [];
        $scanned = [];

        foreach ($this->procedureBodies($text, $ranges) as [$label, $body, $start]) {
            $scanned[$start] = true;

            if ($body === null) {
                $violations[] = "A {$label} body that is unterminated, or written as a string literal continued by another literal, is not allowed: the guard will not guess which text PostgreSQL runs.";

                continue;
            }

            $violations = array_merge($violations, $this->blockBodyViolations($body, $label, $depth + 1));
        }

        foreach ($ranges['quoted'] as [$start, $end]) {
            if (! isset($scanned[$start])) {
                $violations = array_merge($violations, $this->nestedBodyViolations($this->dollarQuotedInner(substr($text, $start, $end - $start)), $depth + 1));
            }
        }

        return $violations;
    }

    /**
     * The checks a PostgreSQL CREATE FUNCTION | PROCEDURE statement needs
     * beyond those of nestedBodyViolations(), which scans a body written as
     * a literal after AS. A SQL-standard body is plain code:
     *  - RETURN <expression> is scanned from RETURN to the end like a body;
     *  - BEGIN ATOMIC ... END holds semicolons the statement splitter takes
     *    for statement ends, so the guard cannot extract it, and refuses it.
     * A routine statement whose body is none of these is refused too, since
     * the guard cannot tell what PostgreSQL would run.
     *
     * @return list<string>
     */
    private function routineBodyViolations(string $sql): array
    {
        $ranges = $this->postgresLexicalRanges($sql, openCommentRunsToEnd: true);

        if ($ranges === null) {
            return [];
        }

        $code = $this->postgresCodeText($sql, $ranges);
        $word = '(?<![A-Za-z0-9_$\x80-\xff])';
        $end = '(?![A-Za-z0-9_$\x80-\xff])';

        if (preg_match("/^\\s*CREATE\\s+(?:OR\\s+REPLACE\\s+)?(?:FUNCTION|PROCEDURE){$end}/i", $code) !== 1) {
            return [];
        }

        if (preg_match("/{$word}BEGIN\\s+ATOMIC{$end}/i", $code) === 1) {
            return ['A function body written as BEGIN ATOMIC ... END is not allowed through QueryProxy: the guard cannot extract it from the statement; write the body as a dollar-quoted string (AS $$ ... $$) instead.'];
        }

        if (preg_match("/{$word}RETURN{$end}/i", $code, $matches, PREG_OFFSET_CAPTURE) === 1) {
            return $this->blockBodyViolations(substr($sql, $matches[0][1]), 'function');
        }

        $bodies = array_filter($this->procedureBodies($sql, $ranges), fn (array $body): bool => $body[0] === 'function');

        return $bodies === []
            ? ['A CREATE FUNCTION or CREATE PROCEDURE statement whose function body the guard cannot find is not allowed through QueryProxy.']
            : [];
    }

    /**
     * The LANGUAGE and SET clauses of PostgreSQL statements that outlive
     * themselves, in $text and, recursively, in every procedural body and
     * dollar-quoted string written inside it:
     *  - a DO block or a CREATE FUNCTION | PROCEDURE runs in plpgsql or sql
     *    only — the languages whose bodies the guard reads. Any other
     *    language, a language written as anything but a plain name or a
     *    plain '...' name, and a routine with no LANGUAGE clause and no
     *    SQL-standard body (where PostgreSQL requires one) are refused. A
     *    DO block without a LANGUAGE clause runs in plpgsql;
     *  - a SET clause of CREATE FUNCTION | PROCEDURE, or of ALTER FUNCTION |
     *    PROCEDURE | ROUTINE | DATABASE | ROLE | USER, carries its setting
     *    into every call or session, so it is held to the rule of a SET
     *    statement (serverVariableViolations()). A clause whose setting the
     *    guard cannot read is refused.
     *
     * The text is read as PostgreSQL reads it (see postgresBlockBodyView()),
     * with every dollar-quoted string replaced by an opaque literal: its
     * contents are judged when the recursion reaches them, as code.
     *
     * @return list<string>
     */
    private function routineClauseViolations(string $text, int $depth = 0): array
    {
        if ($depth >= self::MAX_BLOCK_BODY_QUOTE_DEPTH) {
            return ['A procedural body nested more than '.self::MAX_BLOCK_BODY_QUOTE_DEPTH.' levels deep is not allowed.'];
        }

        $ranges = $this->postgresLexicalRanges($text, openCommentRunsToEnd: true);
        $view = $ranges === null ? null : $this->postgresBlockBodyView($text, openCommentRunsToEnd: true, keepNameStrings: true);

        if ($ranges === null || $view === null) {
            return ['A statement whose LANGUAGE and SET clauses the guard cannot read is not allowed through QueryProxy.'];
        }

        foreach ($ranges['quoted'] as [$start, $end]) {
            $length = $end - $start;
            $view = substr_replace($view, $length >= 2 ? "'".str_repeat(' ', $length - 2)."'" : ' ', $start, $length);
        }

        $view = $this->withQuotedNamesSpelledOut($text, $view, $ranges['strings']);
        $violations = [];

        foreach (explode(';', $view) as $statement) {
            $violations = array_merge(
                $violations,
                $this->routineLanguageViolations($statement),
                $this->routineSetClauseViolations($statement),
                $this->languageDefinitionViolations($statement),
            );
        }

        $dollarStarts = [];

        foreach ($ranges['quoted'] as [$start, $end]) {
            $dollarStarts[$start] = true;
            $violations = array_merge($violations, $this->routineClauseViolations(
                $this->dollarQuotedParts(substr($text, $start, $end - $start))[0],
                $depth + 1,
            ));
        }

        // A '...' body is data to the reading above; its text is code to
        // PostgreSQL, and is read as such here.
        foreach ($this->procedureBodies($text, $ranges) as [, $body, $start]) {
            if ($body !== null && ! isset($dollarStarts[$start])) {
                $violations = array_merge($violations, $this->routineClauseViolations($body, $depth + 1));
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * A reading of $text (see routineClauseViolations()) in which every
     * closed "..." and U&"..." identifier is written back as "<value>",
     * decoded, so the rules that read it can tell a quoted name from a bare
     * one: PostgreSQL folds a bare name to lower case and keeps a quoted one
     * as written, so LANGUAGE "Sql" is not LANGUAGE sql. Only a value made
     * of name characters and dots is written out; any other is left as the
     * reading has it, which the rules cannot read and refuse. A value is
     * never longer than its spelling, so byte positions are kept.
     *
     * @param  list<array{0: int, 1: int, 2: string}>  $strings
     */
    private function withQuotedNamesSpelledOut(string $text, string $view, array $strings): string
    {
        foreach ($strings as [$start, $end, $kind]) {
            $length = $end - $start;

            if ($text[$start] !== '"' || $length < 2 || $text[$end - 1] !== '"') {
                continue;
            }

            $inner = str_replace('""', '"', substr($text, $start + 1, $length - 2));
            $value = $kind === 'unicode' ? $this->decodeUnicodeEscapes($inner, '\\') : $inner;

            if ($value === null || preg_match('/^[A-Za-z0-9_$.\x80-\xff]+$/', $value) !== 1) {
                continue;
            }

            $prefix = $this->stringPrefixLength($kind);
            $view = substr_replace($view, str_pad("\"{$value}\"", $length + $prefix), $start - $prefix, $length + $prefix);
        }

        return $view;
    }

    /**
     * CREATE [OR REPLACE] [TRUSTED] [PROCEDURAL] LANGUAGE defines a language
     * over any handler, and ALTER [PROCEDURAL] LANGUAGE ... RENAME TO
     * renames one: either can give a language the guard does not read
     * (plperl, a C handler) the name of one it allows, so a later DO block or
     * function "in plpgsql" runs that language. Both are refused. Changing a
     * language's owner and dropping one move no name, and pass this rule.
     *
     * @return list<string>
     */
    private function languageDefinitionViolations(string $statement): array
    {
        $word = '(?<![A-Za-z0-9_$\x80-\xff])';
        $end = '(?![A-Za-z0-9_$\x80-\xff])';

        $defines = preg_match("/{$word}CREATE\\s+(?:OR\\s+REPLACE\\s+)?(?:TRUSTED\\s+)?(?:PROCEDURAL\\s+)?LANGUAGE{$end}/i", $statement) === 1;
        $renames = preg_match("/{$word}ALTER\\s+(?:PROCEDURAL\\s+)?LANGUAGE{$end}.*{$word}RENAME{$end}/is", $statement) === 1;

        return $defines || $renames
            ? ['CREATE LANGUAGE and ALTER LANGUAGE ... RENAME are not allowed through QueryProxy: they can give a language the guard cannot read the name of plpgsql or sql.']
            : [];
    }

    /**
     * The language rule of routineClauseViolations() over one statement of
     * a reading.
     *
     * @return list<string>
     */
    private function routineLanguageViolations(string $statement): array
    {
        $word = '(?<![A-Za-z0-9_$\x80-\xff])';
        $end = '(?![A-Za-z0-9_$\x80-\xff])';
        $violations = [];

        if (preg_match("/{$word}CREATE\\s+(?:OR\\s+REPLACE\\s+)?(?:FUNCTION|PROCEDURE){$end}/i", $statement, $match, PREG_OFFSET_CAPTURE) === 1) {
            $rest = substr($statement, $match[0][1] + strlen($match[0][0]));
            // The whole statement is read, a SQL-standard body included: a
            // routine or a type may be named "return" or "begin", so the
            // words cannot mark where the clauses end.
            $languages = $this->languageClauses($rest);

            if ($languages === [] && preg_match("/{$word}(?:RETURN|BEGIN\\s+ATOMIC){$end}/i", $rest) !== 1) {
                $violations[] = 'A CREATE FUNCTION or CREATE PROCEDURE statement without a LANGUAGE clause is not allowed through QueryProxy: write LANGUAGE plpgsql or LANGUAGE sql.';
            }

            foreach ($languages as $language) {
                $violations = array_merge($violations, $this->languageViolations($language, 'CREATE FUNCTION', 'function'));
            }
        }

        // DO opens a block only when a LANGUAGE clause or the body literal
        // follows; ON CONFLICT DO UPDATE and a rule's DO INSTEAD are not one.
        preg_match_all("/{$word}DO\\s*(?=LANGUAGE{$end}|(?:E|U&)?')/i", $statement, $blocks, PREG_OFFSET_CAPTURE);

        foreach ($blocks[0] as [, $position]) {
            foreach ($this->languageClauses(substr($statement, $position)) as $language) {
                $violations = array_merge($violations, $this->languageViolations($language, 'DO', 'block'));
            }
        }

        return $violations;
    }

    /**
     * The language named by every LANGUAGE keyword outside parentheses in a
     * reading, resolved as PostgreSQL resolves it: a bare name is folded to
     * lower case, a "..." name (U& ones are spelled "..." by now, see
     * withQuotedNamesSpelledOut()) and a '...' name are kept as written.
     * Null for a language written any other way, which the guard cannot
     * read.
     *
     * @return list<?string>
     */
    private function languageClauses(string $reading): array
    {
        $end = '(?![A-Za-z0-9_$\x80-\xff])';
        $name = '[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*';
        preg_match_all("/[()]|(?<![A-Za-z0-9_\$\\x80-\\xff])LANGUAGE{$end}/i", $reading, $matches, PREG_OFFSET_CAPTURE);

        $languages = [];
        $depth = 0;

        foreach ($matches[0] as [$token, $position]) {
            if ($token === '(' || $token === ')') {
                $depth = max(0, $depth + ($token === '(' ? 1 : -1));

                continue;
            }

            if ($depth > 0) {
                continue;
            }

            $after = substr($reading, $position + strlen($token));
            // A bare name directly followed by & or a quote is a U& or E prefix
            // the guard could not resolve, not the language itself.
            if (preg_match("/^\\s*(?:({$name})(?![&'\"])|\"({$name})\"|'({$name})')(?!\\s*\\.)/", $after, $language) !== 1) {
                $languages[] = null;
            } elseif ($language[1] !== '') {
                $languages[] = strtolower($language[1]);
            } else {
                $languages[] = $language[2] !== '' ? $language[2] : $language[3];
            }
        }

        return $languages;
    }

    /**
     * Why a DO block ($subject "DO", $noun "block") or a routine ($subject
     * "CREATE FUNCTION", $noun "function") may not run in $language; none
     * for plpgsql and sql.
     *
     * @return list<string>
     */
    private function languageViolations(?string $language, string $subject, string $noun): array
    {
        if ($language === null) {
            return ["A {$subject} statement whose language the guard cannot read is not allowed through QueryProxy: write LANGUAGE plpgsql or LANGUAGE sql as a plain name."];
        }

        if (in_array($language, ['plpgsql', 'sql'], true)) {
            return [];
        }

        if ($language !== strtolower($language)) {
            return ["{$subject} ... LANGUAGE \"{$language}\" is not allowed through QueryProxy: a quoted language name keeps its case, so it is not plpgsql or sql, and only plpgsql and sql bodies are allowed."];
        }

        if ($this->isUntrustedLanguage($language)) {
            return ["{$subject} ... LANGUAGE {$language} is not allowed through QueryProxy: {$language} is an untrusted procedural language, so the {$noun} would run as the database superuser."];
        }

        return ["{$subject} ... LANGUAGE {$language} is not allowed through QueryProxy: only plpgsql and sql bodies are allowed, because they are the only languages the guard can read."];
    }

    /**
     * The SET-clause rule of routineClauseViolations() over one statement of
     * a reading. ALTER FUNCTION ... SET SCHEMA and ALTER DATABASE ... SET
     * TABLESPACE move the object rather than carry a setting, and are
     * skipped.
     *
     * @return list<string>
     */
    private function routineSetClauseViolations(string $statement): array
    {
        $word = '(?<![A-Za-z0-9_$\x80-\xff])';
        $end = '(?![A-Za-z0-9_$\x80-\xff])';
        $name = '[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*';

        if (preg_match("/{$word}(?:CREATE\\s+(?:OR\\s+REPLACE\\s+)?(?:FUNCTION|PROCEDURE)|ALTER\\s+(?:FUNCTION|PROCEDURE|ROUTINE|DATABASE|ROLE|USER)){$end}/i", $statement, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        // The whole statement is read, as in routineLanguageViolations().
        $rest = substr($statement, $match[0][1] + strlen($match[0][0]));
        preg_match_all("/[()]|{$word}SET{$end}/i", $rest, $matches, PREG_OFFSET_CAPTURE);

        $violations = [];
        $depth = 0;

        foreach ($matches[0] as [$token, $position]) {
            if ($token === '(' || $token === ')') {
                $depth = max(0, $depth + ($token === '(' ? 1 : -1));

                continue;
            }

            if ($depth > 0) {
                continue;
            }

            $after = substr($rest, $position + strlen($token));

            if (preg_match("/^\\s+(?:SCHEMA|TABLESPACE){$end}(?!\\s*(?:=|TO{$end}|FROM{$end}))/i", $after) === 1) {
                continue;
            }

            $part = "(?:{$name}|\"[A-Za-z0-9_\$.\\x80-\\xff]+\")";
            $setting = preg_match("/^\\s*({$part}(?:\\s*\\.\\s*{$part})*)\\s*(?:=|TO{$end}|FROM\\s+CURRENT{$end})/i", $after, $parts) === 1
                ? (string) preg_replace('/[\s"]+/', '', $parts[1])
                : null;

            // A quoted part may hold dots ("app.jwt_secret"); a setting name
            // is case-insensitive to PostgreSQL, quoted or not.
            if ($setting === null || preg_match("/^{$name}(?:\\.{$name})*\$/", $setting) !== 1) {
                $violations[] = 'A SET clause on a function, procedure, database or role whose setting the guard cannot read is not allowed through QueryProxy: the guard cannot tell which setting it carries.';

                continue;
            }

            $violations = array_merge($violations, $this->serverVariableViolations(
                $this->significantTokens("SET {$setting} = DEFAULT"),
                DbDriver::Pgsql,
            ));
        }

        return $violations;
    }

    /**
     * The inner text of a dollar-quoted region, whether it is closed, and
     * its tag ($$ or $name$).
     *
     * @return array{0: string, 1: bool, 2: string}
     */
    private function dollarQuotedParts(string $region): array
    {
        $tag = preg_match('/^\$[A-Za-z0-9_\x80-\xff]*\$/', $region, $matches) === 1 ? $matches[0] : '';
        $closed = $tag !== '' && strlen($region) >= 2 * strlen($tag) && str_ends_with($region, $tag);

        return [substr($region, strlen($tag), strlen($region) - strlen($tag) - ($closed ? strlen($tag) : 0)), $closed, $tag];
    }

    /**
     * How many bytes a quoted string's prefix takes before its opening
     * quote: E for an "escape" string, U& for a "unicode" one.
     */
    private function stringPrefixLength(string $kind): int
    {
        return match ($kind) {
            'escape' => 1,
            'unicode' => 2,
            default => 0,
        };
    }

    /**
     * $text with its comments, dollar-quoted strings, '...' strings and
     * "..." identifiers blanked, byte length kept: the code PostgreSQL reads
     * around them. $ranges is postgresLexicalRanges() of $text.
     *
     * @param  array{comments: list<array{0: int, 1: int}>, quoted: list<array{0: int, 1: int}>, strings: list<array{0: int, 1: int, 2: string}>}  $ranges
     */
    private function postgresCodeText(string $text, array $ranges): string
    {
        return $this->blankRanges($text, array_merge(
            $ranges['comments'],
            $ranges['quoted'],
            array_map(fn (array $string): array => [$string[0], $string[1]], $ranges['strings']),
        ));
    }

    private function dollarQuotedInner(string $region): string
    {
        return $this->dollarQuotedParts($region)[0];
    }

    /**
     * The literals of $text that PostgreSQL runs as a procedural body, each
     * as [label, body, start offset], the body null when it cannot be read:
     * the literal is unterminated, or a '...' one is followed by another
     * literal with only whitespace and comments between (string
     * continuation). A '...' or dollar-quoted literal is a body when it
     * follows AS in a statement that creates a function or a procedure; a
     * '...' literal is one when it follows DO (with or without a LANGUAGE
     * clause) — a dollar-quoted DO body is code to the reading already.
     *
     * @param  array{comments: list<array{0: int, 1: int}>, quoted: list<array{0: int, 1: int}>, strings: list<array{0: int, 1: int, 2: string}>}  $ranges  postgresLexicalRanges() of $text
     * @return list<array{0: string, 1: ?string, 2: int}>
     */
    private function procedureBodies(string $text, array $ranges): array
    {
        $literals = array_merge(
            array_map(fn (array $range): array => [$range[0], $range[1], 'dollar'], $ranges['quoted']),
            array_values(array_filter($ranges['strings'], fn (array $string): bool => $text[$string[0]] === "'")),
        );
        usort($literals, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $code = $this->postgresCodeText($text, $ranges);
        $word = '(?<![A-Za-z0-9_$\x80-\xff])';

        $bodies = [];

        foreach ($literals as $index => [$start, $end, $kind]) {
            $before = substr($code, 0, $start - $this->stringPrefixLength($kind));
            $statement = substr($before, (int) strrpos($before, ';'));

            $label = match (true) {
                preg_match("/{$word}AS\\s*\$/i", $before) === 1
                    && preg_match("/{$word}CREATE\\s+(?:OR\\s+REPLACE\\s+)?(?:FUNCTION|PROCEDURE)(?![A-Za-z0-9_\$\\x80-\\xff])/i", $statement) === 1 => 'function',
                $kind !== 'dollar'
                    && preg_match("/{$word}DO(?:\\s+LANGUAGE\\s+(?:U&)?(?:[A-Za-z_\\x80-\\xff][A-Za-z0-9_\$\\x80-\\xff]*)?)?\\s*\$/i", $before) === 1 => 'DO block',
                default => null,
            };

            if ($label === null) {
                continue;
            }

            $region = substr($text, $start, $end - $start);

            if ($kind === 'dollar') {
                [$inner, $closed] = $this->dollarQuotedParts($region);
                $bodies[] = [$label, $closed ? $inner : null, $start];

                continue;
            }

            $closed = preg_match($kind === 'escape' ? "/^'(?:[^'\\\\]|''|\\\\.)*'\$/s" : "/^'(?:[^']|'')*'\$/s", $region) === 1;
            $next = $literals[$index + 1] ?? null;
            $continued = $next !== null
                && trim(substr($code, $end, $next[0] - $this->stringPrefixLength($next[2]) - $end)) === '';

            $bodies[] = [$label, $closed && ! $continued
                ? $this->decodePostgresString(substr($region, 1, -1), $kind)
                : null, $start];
        }

        return $bodies;
    }

    /**
     * Whether a body reading uses EXECUTE as anything but the trigger clause
     * EXECUTE FUNCTION / PROCEDURE name(...), which names a function to call
     * rather than text to run.
     */
    private function executesDynamicSql(string $reading): bool
    {
        $word = '[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*';

        return preg_match(
            "/(?<![A-Za-z0-9_\$\\x80-\\xff])EXECUTE(?![A-Za-z0-9_\$\\x80-\\xff])(?!\\s+(?:FUNCTION|PROCEDURE)\\s+{$word}(?:\\s*\\.\\s*{$word})?\\s*\\()/i",
            $reading,
        ) === 1;
    }

    /**
     * The body with every dollar-quote tag ($$, $tag$) replaced by spaces of
     * the same length, so its nested dollar-quoted strings read as one text.
     */
    private function withoutDollarTags(string $body): string
    {
        return (string) preg_replace_callback(
            '/(?<![A-Za-z0-9_$\x80-\xff])\$(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?\$/',
            fn (array $match): string => str_repeat(' ', strlen($match[0])),
            $body,
        );
    }

    /**
     * The body of a DO block as PostgreSQL reads it, respelled so that the
     * MySQL-first lexer behind normalize() cannot read it any other way.
     *
     * The lexer takes a backslash as an escape in every string and "#" as
     * the start of a comment; PL/pgSQL (standard_conforming_strings on) takes
     * neither. "PERFORM 'a\', pg_read_file('/etc/passwd') --'" is one string
     * to the lexer and a call to PostgreSQL, and so is "PERFORM 1 # 1,
     * pg_read_file(...)". Every region is therefore located with
     * postgresLexicalRanges() and rewritten, byte length kept:
     *  - comments are blanked (they nest, and "--" needs no space after it);
     *  - '...' strings — plain, E'...' and U&'...' — keep their quotes and
     *    have their contents blanked, so no backslash is left in one;
     *  - a "..." identifier that is a plain name is unquoted, so a quoted
     *    call ("pg_read_file"(...)) meets the same patterns as a bare one;
     *    any other one is blanked like a string;
     *  - "#" left in code is the XOR operator and becomes a space;
     *  - a nested dollar-quoted string keeps its tags and has its contents
     *    rewritten the same way: inside a body it is how a statement is
     *    spelled for EXECUTE, and the scan wants to read that statement.
     *
     * A U&"..." identifier is decoded with the default backslash escape
     * and, when the result is a plain name, written out bare in place of the
     * whole spelling: U&"\0070g_read_file"(...) is pg_read_file(...) to
     * PostgreSQL. A UESCAPE clause is not decoded; the caller refuses it.
     *
     * Null when a block comment in the body is never closed, or when dollar
     * quoting is nested deeper than MAX_BLOCK_BODY_QUOTE_DEPTH. Inside a
     * nested dollar-quoted string an open block comment only runs to the end
     * of that string: the string is data, valid however it ends, and the code
     * written before the comment is still scanned.
     */
    private function postgresBlockBodyView(string $body, int $depth = 0, bool $openCommentRunsToEnd = false, bool $keepNameStrings = false): ?string
    {
        if ($depth > self::MAX_BLOCK_BODY_QUOTE_DEPTH) {
            return null;
        }

        $ranges = $this->postgresLexicalRanges($body, $openCommentRunsToEnd);

        if ($ranges === null) {
            return null;
        }

        $view = $this->blankRanges($body, $ranges['comments']);
        foreach ($ranges['strings'] as [$start, $end, $kind]) {
            $length = $end - $start;
            $quote = $body[$start];
            $closed = $length >= 2 && $body[$end - 1] === $quote;
            $inner = substr($body, $start + 1, $closed ? $length - 2 : $length - 1);

            $name = $quote === '"' && $closed && $kind === 'unicode'
                ? $this->decodeUnicodeEscapes(str_replace('""', '"', $inner), '\\')
                : null;

            if ($name !== null && $this->isPlainPostgresName($name)) {
                // U& and the quotes go too; an escape is never shorter than
                // the character it names, so the name always fits.
                $view = substr_replace($view, str_pad(' '.$name, $length + 2), $start - 2, $length + 2);

                continue;
            }

            if ($keepNameStrings && $quote === "'" && $closed) {
                $value = $this->decodePostgresString($inner, $kind);

                if (preg_match('/^[A-Za-z0-9_.]+$/', $value) === 1) {
                    // E and U& go too, so the lexer meets a plain literal;
                    // a decoded value is never longer than its spelling.
                    $prefix = $this->stringPrefixLength($kind);
                    $view = substr_replace($view, str_pad("'{$value}'", $length + $prefix), $start - $prefix, $length + $prefix);

                    continue;
                }
            }

            if ($quote === '"' && $closed && $this->isPlainPostgresName($inner)) {
                $replacement = ' '.$inner.' ';
            } else {
                // An unterminated string runs to the end of the body; it is
                // closed here as well, so the lexer reads no further than
                // PostgreSQL does. A lone quote at the very end is dropped.
                $replacement = $length >= 2 ? $quote.str_repeat(' ', $length - 2).$quote : ' ';
            }

            $view = substr_replace($view, $replacement, $start, $length);
        }

        foreach ($ranges['quoted'] as [$start, $end]) {
            [$inner, $closed, $tag] = $this->dollarQuotedParts(substr($body, $start, $end - $start));
            $innerView = $this->postgresBlockBodyView($inner, $depth + 1, openCommentRunsToEnd: true, keepNameStrings: $keepNameStrings);

            if ($innerView === null) {
                return null;
            }

            $view = substr_replace($view, $tag.$innerView.($closed ? $tag : ''), $start, $end - $start);
        }

        $opaque = array_merge($ranges['comments'], $ranges['quoted'], array_map(
            fn (array $string): array => [$string[0], $string[1]],
            $ranges['strings'],
        ));

        foreach ($this->bytePositions($body, '#') as $position) {
            if (! $this->isWithinRange($position, $opaque)) {
                $view[$position] = ' ';
            }
        }

        return $view;
    }

    private function isPlainPostgresName(string $name): bool
    {
        return preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*$/', $name) === 1;
    }

    /**
     * The string literals of a DO statement — its '...' strings, E'...' and
     * U&'...' included, and its dollar-quoted strings — located the way
     * PostgreSQL reads them (see postgresLexicalRanges()), in source order.
     * Each is [start, end, kind], kind being "dollar" or the backslash
     * reading of a quoted string. A "..." identifier is not a literal.
     *
     * PostgreSQL's grammar takes a literal in two places of a DO statement:
     * as the body, and as the language name after LANGUAGE. It also joins
     * '...' literals separated by whitespace holding a newline into one
     * (string continuation). The caller therefore accepts exactly one
     * literal, not after LANGUAGE, as the body; anything else is refused.
     *
     * @param  array{comments: list<array{0: int, 1: int}>, quoted: list<array{0: int, 1: int}>, strings: list<array{0: int, 1: int, 2: string}>}  $ranges  postgresLexicalRanges() of $sql
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private function anonymousBlockLiterals(string $sql, array $ranges): array
    {
        $literals = array_map(fn (array $range): array => [$range[0], $range[1], 'dollar'], $ranges['quoted']);

        foreach ($ranges['strings'] as $string) {
            if ($sql[$string[0]] === "'") {
                $literals[] = $string;
            }
        }

        usort($literals, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $literals;
    }

    /**
     * True when one of the literals is the operand of a LANGUAGE keyword:
     * LANGUAGE 'plpgsql', LANGUAGE $$plpgsql$$, comments in between allowed.
     *
     * @param  list<array{0: int, 1: int, 2: string}>  $literals
     * @param  list<array{0: int, 1: int}>  $comments
     */
    private function languageLiteral(string $sql, array $literals, array $comments): bool
    {
        $code = $this->blankRanges($sql, $comments);

        foreach ($literals as [$start, , $kind]) {
            if (preg_match('/(?<![A-Za-z0-9_$\x80-\xff])LANGUAGE\s*$/i', substr($code, 0, $start - $this->stringPrefixLength($kind))) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The source text a DO block asks the server to execute, given its one
     * body literal (see anonymousBlockLiterals()): the contents of a
     * dollar-quoted body, or the decoded value of a quoted one. Nested
     * dollar quoting is left in place — inside the body it is quoting, not
     * framing, and the body scan wants to see through it.
     *
     * A quoted body is decoded the way PostgreSQL reads it, not the way the
     * lexer does: the lexer takes a backslash in a plain string as an escape
     * and does not know E'...' strings, so its reading of the body text would
     * differ from the text the server hands to PL/pgSQL.
     *
     * @param  array{0: int, 1: int, 2: string}  $literal
     */
    private function anonymousBlockBody(string $sql, array $literal): string
    {
        [$start, $end, $kind] = $literal;
        $region = substr($sql, $start, $end - $start);

        if ($kind === 'dollar') {
            return $this->dollarQuotedParts($region)[0];
        }

        $closed = strlen($region) >= 2 && str_ends_with($region, "'");

        return $this->decodePostgresString(substr($region, 1, strlen($region) - ($closed ? 2 : 1)), $kind);
    }

    /**
     * The value of a PostgreSQL '...' string, given the text between its
     * quotes and how it reads a backslash ("plain", "escape" for E'...',
     * "unicode" for U&'...' with the default escape character). A doubled
     * quote is one quote in every kind.
     */
    private function decodePostgresString(string $inner, string $kind): string
    {
        if ($kind === 'plain') {
            return str_replace("''", "'", $inner);
        }

        $pattern = $kind === 'escape'
            ? '/\'\'|\\\\(?:[0-7]{1,3}|x[0-9A-Fa-f]{1,2}|u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|.)/s'
            : '/\'\'|\\\\(?:[0-9A-Fa-f]{4}|\+[0-9A-Fa-f]{6}|\\\\)/';

        return (string) preg_replace_callback($pattern, function (array $match) use ($kind): string {
            $escape = $match[0];

            if ($escape === "''") {
                return "'";
            }

            $body = substr($escape, 1);

            if ($kind === 'unicode') {
                return $body === '\\' ? '\\' : (mb_chr((int) hexdec(ltrim($body, '+')), 'UTF-8') ?: '');
            }

            return match (true) {
                preg_match('/^[0-7]+$/', $body) === 1 => chr(octdec($body) & 0xFF),
                $body[0] === 'x' && strlen($body) > 1 => chr((int) hexdec(substr($body, 1))),
                ($body[0] === 'u' || $body[0] === 'U') && strlen($body) > 1 => mb_chr((int) hexdec(substr($body, 1)), 'UTF-8') ?: '',
                default => ['b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t"][$body] ?? $body,
            };
        }, $inner);
    }

    /**
     * Apply the unconditional denylist to the body of a DO block, so that
     * DROP DATABASE, GRANT, CREATE FUNCTION ... SONAME, pg_read_file() and the
     * rest cannot be smuggled in as procedural code. Without this the "always
     * rejected" list would mean nothing: a DO block would be a hole straight
     * through it.
     *
     * The body is not a statement list the parser can split, so the patterns
     * are matched against fragments instead. The normalized body is cut at
     * every semicolon, at every nested dollar tag and at the plpgsql keywords
     * that introduce a statement (BEGIN, THEN, EXECUTE, ...), which is what
     * gives the anchored patterns a statement start to bind to.
     *
     * This is best-effort and deliberately so. It sees SQL that was written
     * out in plain text; it cannot see SQL a body computes at run time —
     * EXECUTE format('DR'||'OP DATABASE prod') or EXECUTE a_variable will pass
     * it, and no static scan of a procedural body can do otherwise. It is the
     * last net under an approval that should not have been given, not the
     * boundary: the DBA approval workflow remains what authorizes a DO block.
     *
     * @return list<string>
     */
    private function forbiddenInBlockBody(string $body, string $label = 'DO block'): array
    {
        // Dollar quoting is NOT masked here: a nested $q$ ... $q$ region is how
        // a body spells a quoted statement, and masking it would hide exactly
        // the plain text this scan exists to read.
        $normalized = $this->normalize($body, maskDollarQuoted: false);

        $fragments = preg_split(
            '/;|\$[A-Za-z0-9_\x80-\xff]*\$|\b(?:BEGIN|DECLARE|END|THEN|ELSE|ELSIF|LOOP|EXECUTE|PERFORM|RETURN)\b/i',
            $normalized,
        ) ?: [];

        $violations = [];

        foreach ($fragments as $fragment) {
            $fragment = trim($fragment);

            if ($fragment === '') {
                continue;
            }

            foreach (self::FORBIDDEN_PATTERNS as $pattern => $message) {
                if (preg_match($pattern, $fragment)) {
                    // Keyed by message so one body cannot report the same
                    // violation once per fragment.
                    $violations[$message] = "Inside the {$label} body: {$message}";
                }
            }
        }

        return array_values($violations);
    }

    /**
     * SET is judged by the variable being written and by the scope it is
     * written in. On MySQL / MariaDB only the persistent scopes are guarded,
     * in both the keyword form (SET GLOBAL x = ...) and the variable form
     * (SET @@GLOBAL.x = ...); a SESSION-scoped write changes nothing for
     * anyone else.
     *
     * PostgreSQL has no persistent scope to guard: SET, SET SESSION and
     * SET LOCAL all change the running session, and that is where a preload
     * library or a replication role takes effect. On PostgreSQL, and when the
     * driver is unknown, a listed name is therefore rejected in every scope.
     *
     * The settings in LEXER_MODE_VARIABLES are the exception: they change how
     * the guard's own reading of later SQL lines up with the server's, so
     * they are rejected in every scope on every driver, including the
     * "@@x" session spelling and MariaDB's SET STATEMENT ... FOR form.
     *
     * When the statement carries a leading scope keyword it is applied to
     * every bare assignment in the list, which is the conservative reading:
     * servers differ on whether SET GLOBAL a = 1, b = 2 scopes b globally too,
     * and over-rejecting there is cheaper than guessing wrong.
     *
     * @param  list<Token>  $tokens
     * @return list<string>
     */
    private function serverVariableViolations(array $tokens, ?DbDriver $driver): array
    {
        $everyScope = $driver === null || $driver === DbDriver::Pgsql;
        $statementScope = null;
        $index = 1;

        // A leading GLOBAL / PERSIST keyword, but not the "@@GLOBAL" symbol,
        // whose value spells the same word and is handled per assignment.
        if (isset($tokens[1])
            && $tokens[1]->type !== TokenType::Symbol
            && in_array(strtoupper((string) $tokens[1]->value), self::PERSISTENT_SCOPES, true)) {
            $statementScope = strtoupper((string) $tokens[1]->value);
            $index = 2;
        }

        // MariaDB's "SET STATEMENT a = 1, b = 2 FOR <statement>": the
        // assignments end at the top-level FOR, and the statement after it
        // is not part of the assignment list.
        if ($statementScope === null
            && isset($tokens[1])
            && $tokens[1]->type !== TokenType::Symbol
            && strtoupper((string) $tokens[1]->value) === 'STATEMENT') {
            $index = 2;
            $forIndex = $this->topLevelForIndex($tokens, $index);

            if ($forIndex !== null) {
                $tokens = array_slice($tokens, 0, $forIndex);
            }
        }

        $violations = [];
        $dangerous = $this->dangerousVariables();

        foreach ($this->splitAssignments($tokens, $index) as $assignment) {
            [$scope, $name] = $this->variableReference($assignment, $statementScope);

            if ($name === null || ! in_array($name, $dangerous, true)) {
                continue;
            }

            $written = $scope === null ? 'SET' : "SET {$scope}";

            if (in_array($name, self::LEXER_MODE_VARIABLES, true)
                || in_array($scope, self::PERSISTENT_SCOPES, true)
                || $everyScope) {
                $violations[] = "{$written} {$name} is not allowed through QueryProxy: ".$this->dangerousVariableReason($name);
            }
        }

        return $violations;
    }

    /**
     * Why writing the dangerous setting $name is refused.
     */
    private function dangerousVariableReason(string $name): string
    {
        return in_array($name, self::LEXER_MODE_VARIABLES, true)
            ? "{$name} changes how quotes and backslashes are read, which the guard cannot follow."
            : "{$name} controls server-side file paths, code loading or a protection switch.";
    }

    /**
     * The index of the top-level FOR keyword that ends a SET STATEMENT's
     * assignment list, or null when there is none. A FOR inside parentheses
     * (a subquery's FOR UPDATE) does not count.
     *
     * @param  list<Token>  $tokens
     */
    private function topLevelForIndex(array $tokens, int $index): ?int
    {
        $depth = 0;

        for ($count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token->type === TokenType::Operator && $token->token === '(') {
                $depth++;
            } elseif ($token->type === TokenType::Operator && $token->token === ')') {
                $depth--;
            } elseif ($depth === 0 && $token->type === TokenType::Keyword && ($this->tokenWords($token)[0] ?? '') === 'FOR') {
                return $index;
            }
        }

        return null;
    }

    /**
     * Break a SET statement's assignment list on its top-level commas.
     *
     * @param  list<Token>  $tokens
     * @return list<list<Token>>
     */
    private function splitAssignments(array $tokens, int $index): array
    {
        $assignments = [[]];
        $depth = 0;

        for ($count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token->type === TokenType::Operator) {
                if ($token->token === '(') {
                    $depth++;
                } elseif ($token->token === ')') {
                    $depth--;
                } elseif ($token->token === ',' && $depth === 0) {
                    $assignments[] = [];

                    continue;
                }
            }

            $assignments[count($assignments) - 1][] = $token;
        }

        return array_values(array_filter($assignments));
    }

    /**
     * Resolve one assignment to the scope it writes in and the variable name
     * it writes, or [null, null] when neither can be read.
     *
     * @param  list<Token>  $assignment
     * @return array{0: ?string, 1: ?string}
     */
    private function variableReference(array $assignment, ?string $statementScope): array
    {
        $first = $assignment[0];

        if ($first->type === TokenType::Symbol && str_starts_with($first->token, '@@')) {
            $scope = strtoupper((string) $first->value);
            $offset = 1;

            if ($scope === '') {
                // "SET @@ GLOBAL . x": with spaces the lexer leaves "@@"
                // standing alone and the scope word lands in the next token.
                $scope = isset($assignment[1]) ? strtoupper((string) $assignment[1]->value) : '';
                $offset = 2;
            }

            // "@@x" with no qualifying dot is a session variable, and the
            // word read as the scope is in fact its name.
            if (! isset($assignment[$offset]) || $assignment[$offset]->token !== '.') {
                return $scope === '' ? [null, null] : ['SESSION', $this->identifierValue($assignment[$offset - 1])];
            }

            return [$scope, isset($assignment[$offset + 1]) ? $this->identifierValue($assignment[$offset + 1]) : null];
        }

        // "@x" is a user variable, never a server setting.
        if ($first->type === TokenType::Symbol && str_starts_with($first->token, '@')) {
            return [null, null];
        }

        $leading = strtoupper((string) $first->value);

        if (in_array($leading, [...self::PERSISTENT_SCOPES, 'SESSION', 'LOCAL'], true)) {
            return [$leading, $this->settingNameAt($assignment, 1)];
        }

        return [$statementScope, $this->settingNameAt($assignment, 0)];
    }

    /**
     * The setting name an assignment writes, starting at $index. A
     * PostgreSQL U&"..." name reaches the lexer as three tokens — U, & and
     * the quoted text with its escapes undecoded — and is decoded here the
     * way PostgreSQL decodes it, so U&"session\005Freplication_role" is
     * judged as session_replication_role. Null when there is no name, or
     * when the escapes do not decode (PostgreSQL rejects such a name).
     *
     * @param  list<Token>  $assignment
     */
    private function settingNameAt(array $assignment, int $index): ?string
    {
        if (! isset($assignment[$index])) {
            return null;
        }

        $quoted = $assignment[$index + 2] ?? null;

        if (strtoupper((string) $assignment[$index]->token) === 'U'
            && isset($assignment[$index + 1])
            && $assignment[$index + 1]->token === '&'
            && $quoted !== null
            && $quoted->type === TokenType::String
            && str_starts_with((string) $quoted->token, '"')) {
            $decoded = $this->decodeUnicodeEscapes(str_replace('""', '"', substr((string) $quoted->token, 1, -1)), '\\');

            return $decoded === null ? null : strtolower($decoded);
        }

        return $this->identifierValue($assignment[$index]);
    }

    /**
     * Comment- and whitespace-free tokens of a statement whose dollar-quoted
     * bodies have been masked to a plain string literal.
     *
     * @return list<Token>
     */
    private function significantTokens(string $sql): array
    {
        $tokens = [];

        foreach ($this->lexTokens($this->maskDollarQuoted($sql)) as $token) {
            if (in_array($token->type, [TokenType::Comment, TokenType::Whitespace, TokenType::Delimiter], true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Bare, case-folded name behind an identifier token: the lexer has already
     * stripped the quotes of `x`, "x" and 'x' into the token value, and the
     * trailing colon of the ":=" assignment form is dropped here — the lexer
     * reports "general_log:=1" as a label token named "general_log:", and
     * "general_log := 1" as one named "general_log :", whitespace included.
     */
    private function identifierValue(Token $token): string
    {
        return strtolower(rtrim((string) $token->value, ": \t\n\r\v\f"));
    }

    /**
     * @return list<string>
     */
    private function untrustedLanguages(): array
    {
        return $this->guardList(self::UNTRUSTED_LANGUAGES, 'queryproxy.untrusted_languages');
    }

    /**
     * @return list<string>
     */
    private function dangerousVariables(): array
    {
        return $this->guardList(self::DANGEROUS_VARIABLES, 'queryproxy.dangerous_variables');
    }

    /**
     * @return list<string>
     */
    private function blockedFunctions(): array
    {
        return $this->guardList(self::BLOCKED_FUNCTIONS, 'queryproxy.blocked_functions');
    }

    /**
     * @return list<string>
     */
    private function stateChangingFunctions(): array
    {
        return $this->guardList(self::STATE_CHANGING_FUNCTIONS, 'queryproxy.state_changing_functions');
    }

    /**
     * @return list<string>
     */
    private function resourceConsumingFunctions(): array
    {
        return $this->guardList(self::RESOURCE_CONSUMING_FUNCTIONS, 'queryproxy.resource_consuming_functions');
    }

    /**
     * @return list<string>
     */
    private function blockedProcedures(): array
    {
        return $this->guardList(self::BLOCKED_PROCEDURES, 'queryproxy.blocked_procedures');
    }

    /**
     * The one allowlist among the guard lists: configuration extending it
     * allows more pragmas, it never removes the read-only floor.
     *
     * @return list<string>
     */
    private function allowedPragmas(): array
    {
        return $this->guardList(self::SQLITE_ALLOWED_PRAGMAS, 'queryproxy.sqlite_allowed_pragmas');
    }

    /**
     * Merge a configured guard list on top of its hard-coded floor. The merge
     * direction is the point: configuration (and through it the environment)
     * can only ever add names, so neither a typo in .env nor a missing config
     * file can quietly shorten a security list.
     *
     * @param  list<string>  $floor
     * @return list<string>
     */
    private function guardList(array $floor, string $key): array
    {
        $configured = array_filter((array) config($key, []), 'is_string');

        return array_values(array_unique(array_merge(
            $floor,
            array_map(fn (string $name): string => strtolower(trim($name)), $configured),
        )));
    }

    /**
     * Byte ranges of the dollar-quoted regions ($$ ... $$ or $tag$ ... $tag$)
     * of a statement, ignoring the ones that only appear inside a quoted
     * literal or a comment.
     *
     * An opening tag with no matching close is not treated as a region: that
     * keeps the previous, stricter tokenization instead of letting a stray tag
     * swallow the rest of the statement and hide it from the guard.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function dollarQuotedRanges(string $sql): array
    {
        if (! str_contains($sql, '$')) {
            return [];
        }

        $ranges = [];
        $length = strlen($sql);
        $offset = 0;

        while ($offset < $length) {
            $char = $sql[$offset];

            if ($char === "'" || $char === '"' || $char === '`') {
                $offset = $this->skipQuoted($sql, $offset);

                continue;
            }

            if ($char === '#' || ($char === '-' && ($sql[$offset + 1] ?? '') === '-')) {
                $newline = strpos($sql, "\n", $offset);
                $offset = $newline === false ? $length : $newline + 1;

                continue;
            }

            if ($char === '/' && ($sql[$offset + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $offset + 2);
                $offset = $end === false ? $length : $end + 2;

                continue;
            }

            // A "$" that continues an identifier (a$$) opens no quote.
            if ($char === '$'
                && ! $this->isPostgresIdentifierCharacter($offset > 0 ? $sql[$offset - 1] : '')
                && preg_match('/\$[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\$|\$\$/A', $sql, $matches, 0, $offset)) {
                $tag = $matches[0];
                $close = strpos($sql, $tag, $offset + strlen($tag));

                if ($close !== false) {
                    $ranges[] = [$offset, $close + strlen($tag)];
                    $offset = $close + strlen($tag);

                    continue;
                }
            }

            $offset++;
        }

        return $ranges;
    }

    /**
     * Advance past a quoted literal or quoted identifier, honouring both the
     * doubled-quote and the backslash escape forms.
     */
    private function skipQuoted(string $sql, int $start): int
    {
        $quote = $sql[$start];
        $length = strlen($sql);
        $offset = $start + 1;

        while ($offset < $length) {
            if ($sql[$offset] === '\\' && $quote !== '`') {
                $offset += 2;

                continue;
            }

            if ($sql[$offset] === $quote) {
                if (($sql[$offset + 1] ?? '') === $quote) {
                    $offset += 2;

                    continue;
                }

                return $offset + 1;
            }

            $offset++;
        }

        return $length;
    }

    /**
     * Replace every dollar-quoted region with a plain string literal, so the
     * MySQL-first lexer sees the body for what it is instead of tokenizing it
     * as SQL.
     */
    private function maskDollarQuoted(string $sql): string
    {
        foreach (array_reverse($this->dollarQuotedRanges($sql)) as [$start, $end]) {
            $sql = substr($sql, 0, $start)."'?'".substr($sql, $end);
        }

        return $sql;
    }

    /**
     * Make the comments the lexer sees the comments the target server sees.
     *
     * The lexer reads comments the MySQL way: a block comment ends at the
     * first "*" + "/", and "--" starts a comment only when whitespace follows.
     * PostgreSQL nests block comments and starts a comment at any "--". Where
     * the two readings differ, text one of them treats as a comment is code to
     * the other, so a guard reading the lexer's view could be shown one
     * statement while the server runs another.
     *
     *  - PostgreSQL: the comments are located with PostgreSQL's own lexical
     *    rules. When the lexer agrees on every one of them the SQL is left as
     *    it is; otherwise each PostgreSQL comment is blanked out (same length,
     *    newlines kept), which is what PostgreSQL ignores anyway, so the lexer
     *    is left with nothing it could misread as a comment.
     *  - Unknown driver: the statement may run under either reading, so any
     *    comment the two readings disagree on is rejected. That covers a
     *    MySQL executable comment ("/*!...*\/"), which the lexer reads as SQL
     *    and PostgreSQL as a comment, and a MariaDB one ("/*M!...*\/"), which
     *    both read as a comment but MariaDB runs.
     *  - MariaDB: the server runs the body of a "/*M!...*\/" comment, which
     *    the lexer reads as a plain comment. Each one is respelled as the
     *    "/*!...*\/" comment MariaDB treats identically (see
     *    exposeMariadbExecutableComments()), so the lexer reads its body as
     *    SQL and the guard judges it, whatever version condition it carries.
     *    On MySQL "/*M!" is a plain comment and is left alone.
     *  - Every driver: a block comment that is never closed is rejected.
     *
     * A "#" comment is left to the lexer: it is a comment in MySQL only. On
     * PostgreSQL and an unknown driver a "#" outside a string or a comment is
     * refused afterwards (see postgresLexicalViolation()).
     *
     * @return array{0: string, 1: ?string} [sql to inspect, violation]
     */
    private function resolveComments(string $sql, ?DbDriver $driver): array
    {
        $unterminated = 'A block comment that is never closed is not allowed.';

        if ($driver !== null && $driver !== DbDriver::Pgsql) {
            // Checked on the SQL as written: a respelled opener left unclosed
            // is no longer one comment token to the lexer.
            if ($this->hasUnterminatedBlockComment($sql)) {
                return [$sql, $unterminated];
            }

            if ($driver !== DbDriver::Mariadb) {
                return [$sql, null];
            }

            // One "/*M!" comment holding another is a single token as
            // written; once both are exposed the outer one may be left
            // without a closing marker.
            $exposed = $this->exposeMariadbExecutableComments($sql);

            if ($exposed === null) {
                return [$sql, 'MariaDB executable comments ("/*M!...*/") nested more than '.self::MAX_EXECUTABLE_COMMENT_DEPTH.' levels deep are not allowed.'];
            }

            if ($exposed !== $sql && $this->hasUnclosedExecutableComment($exposed)) {
                return [$sql, $unterminated];
            }

            return [$exposed, null];
        }

        // An unclosed comment the lexer reports but PostgreSQL does not (one
        // opened inside a dollar-quoted string, say) shows up below as a
        // disagreement between the two readings.
        $postgres = $this->postgresLexicalRanges($sql);

        if ($postgres === null) {
            return [$sql, $unterminated];
        }

        $masked = $sql;

        foreach ($postgres['quoted'] as [$start, $end]) {
            $masked = substr_replace($masked, "'".str_repeat(' ', $end - $start - 2)."'", $start, $end - $start);
        }

        // MariaDB reads "$$" as a name, not a dollar quote, so the comment is
        // looked for in the SQL as written as well as in the masked text.
        if ($driver === null && ($this->mariadbExecutableCommentOffsets($sql) !== [] || $this->mariadbExecutableCommentOffsets($masked) !== [])) {
            return [$sql, 'A MariaDB executable comment ("/*M!...*/") is not allowed when the target database is unknown: MariaDB runs its body while MySQL and PostgreSQL skip it.'];
        }

        if ($postgres['comments'] !== $this->lexerCommentRanges($masked)) {
            if ($driver === null) {
                return [$sql, 'A comment that MySQL and PostgreSQL read differently (a nested block comment, or "--" not followed by a space) is not allowed when the target database is unknown.'];
            }

            $sql = $this->blankRanges($sql, $postgres['comments']);
        }

        // The guard masks dollar-quoted strings with its own scanner; when it
        // does not delimit them exactly where PostgreSQL does (an unclosed
        // tag, a backslash read differently before a tag), part of the
        // statement would be judged as a string while the server runs it.
        if ($postgres['quoted'] !== $this->dollarQuotedRanges($sql)) {
            return [$sql, 'A dollar-quoted string QueryProxy cannot delimit unambiguously is not allowed.'];
        }

        return [$sql, null];
    }

    /**
     * Respell every MariaDB executable comment the lexer reads as a plain
     * comment ("/*M!" + optional version) as the MySQL-style executable
     * comment MariaDB reads the same way ("/*!" + the same version), so the
     * lexer tokenizes its body as SQL.
     *
     * The respelled text is also the text the server runs, so it has to mean
     * the same to MariaDB. MariaDB takes exactly five or six digits after the
     * marker as a version and anything shorter as part of the body; the
     * version is copied and a space is put where the "M" was dropped, so a
     * digit the server reads as SQL is never glued to the version and the
     * text keeps its byte length. One exception: MariaDB skips a "/*!" (but
     * not a "/*M!") comment versioned 50700-99999 as MySQL-only, so such a
     * version (by value: "/*M!060000" too) is blanked instead. Every MariaDB release since 10.0 is above
     * it and runs that body unconditionally, which "/*!" with no version
     * does as well.
     *
     * A version that is kept still decides whether the server runs the body;
     * inspect() judges the request both ways (see skippedCommentReading()).
     *
     * Every comment the lexer reports is respelled in one pass. A body that
     * is exposed this way may hold another "/*M!" comment, so the text is
     * lexed again, at most MAX_EXECUTABLE_COMMENT_DEPTH times; SQL that still
     * holds one after that is refused (null). The work stays linear in the
     * length of the SQL however many comments it holds.
     */
    private function exposeMariadbExecutableComments(string $sql): ?string
    {
        for ($pass = 0; $pass < self::MAX_EXECUTABLE_COMMENT_DEPTH; $pass++) {
            $offsets = $this->mariadbExecutableCommentOffsets($sql);

            if ($offsets === []) {
                return $sql;
            }

            $respelled = '';
            $cursor = 0;

            foreach ($offsets as $offset) {
                preg_match('/\G\/\*M!(\d{5,6})?/', $sql, $match, 0, $offset);
                $version = $match[1] ?? '';

                if ($version !== '' && (int) $version >= 50700 && (int) $version <= 99999) {
                    $version = str_repeat(' ', strlen($version));
                }

                $respelled .= substr($sql, $cursor, $offset - $cursor).'/*!'.$version.' ';
                $cursor = $offset + strlen($match[0]);
            }

            $sql = $respelled.substr($sql, $cursor);
        }

        return $this->mariadbExecutableCommentOffsets($sql) === [] ? $sql : null;
    }

    /**
     * True when an executable comment opener ("/*!", with or without a
     * version) is not matched by a closing "*" + "/" token.
     */
    private function hasUnclosedExecutableComment(string $sql): bool
    {
        $depth = 0;

        foreach ($this->lexTokens($sql) as $token) {
            if ($token->type !== TokenType::Comment) {
                continue;
            }

            if (preg_match('/^\/\*!\d*$/', $token->token)) {
                $depth++;
            } elseif ($token->token === '*/' && $depth > 0) {
                $depth--;
            }
        }

        return $depth > 0;
    }

    /**
     * Byte offsets of every comment token that opens with "/*M!", found in
     * one lexer pass.
     *
     * @return list<int>
     */
    private function mariadbExecutableCommentOffsets(string $sql): array
    {
        if (! str_contains($sql, '/*M!')) {
            return [];
        }

        $offsets = [];

        foreach ($this->tokensWithByteOffsets($sql) as [$token, $offset]) {
            if ($token->type === TokenType::Comment && str_starts_with((string) $token->token, '/*M!')) {
                $offsets[] = $offset;
            }
        }

        return $offsets;
    }

    /**
     * The reading of MySQL / MariaDB SQL in which the server skips every
     * version-conditional comment ("/*!NNNNN ... *\/"): the body of such a
     * comment runs only when the server's version is at least NNNNN, and
     * MariaDB skips a five-digit one versioned 50700-99999 whatever its
     * version. The guard reads the body as SQL, so inspect() judges this
     * second reading too. Returns null for the reading when the SQL holds no
     * such comment (or the driver has none), and the violation when the
     * comments cannot be read safely (see versionConditionalComments()).
     *
     * @return array{0: ?string, 1: ?string} [skipped reading, violation]
     */
    private function skippedCommentReading(string $sql, ?DbDriver $driver): array
    {
        if (($driver !== DbDriver::Mysql && $driver !== DbDriver::Mariadb) || ! str_contains($sql, '/*!')) {
            return [null, null];
        }

        [$ranges, $violation] = $this->versionConditionalComments($sql, $driver);

        if ($violation !== null) {
            return [null, $violation];
        }

        return [$ranges === [] ? null : $this->blankRanges($sql, $ranges), null];
    }

    /**
     * Byte ranges of the version-conditional comments ("/*!NNNNN ... *\/"),
     * opener to closing marker, as the server skips them, or the reason they
     * cannot be located safely. Every condition below keeps the guard to two
     * readings that cover every server version: all bodies run, or all are
     * skipped.
     *
     *  - The version has five digits, or six on MariaDB. The lexer takes
     *    every digit after "/*!" as the version while the server takes five
     *    (six on MariaDB and recent MySQL releases), so any other count would
     *    be read differently.
     *  - Every such comment carries the same version. With two versions a
     *    server between them runs one body and skips the other — a third
     *    reading the guard does not judge.
     *  - The comment is not opened inside another executable comment and
     *    holds no comment itself, and the first "*" + "/" after the opener is
     *    the one the lexer closes it at. The server skips the body as raw
     *    text: it does not see the strings in it, and it lets one nested
     *    comment move the end. Either one would end the comment at another
     *    place than the guard reads.
     *  - The comment is closed.
     *
     * @return array{0: list<array{0: int, 1: int}>, 1: ?string} [ranges, violation]
     */
    private function versionConditionalComments(string $sql, DbDriver $driver): array
    {
        $versionLengths = $driver === DbDriver::Mariadb ? [5, 6] : [5];
        $ranges = [];
        $versions = [];
        $openers = [];
        $versioned = null;

        foreach ($this->tokensWithByteOffsets($sql) as [$token, $offset]) {
            if ($token->type !== TokenType::Comment) {
                continue;
            }

            $text = (string) $token->token;

            if ($text === '*/') {
                $opener = array_pop($openers);

                if ($opener !== null && $opener === $versioned) {
                    $bodyStart = $opener[1];

                    if (strpos($sql, '*/', $bodyStart) !== $offset || str_contains(substr($sql, $bodyStart, $offset - $bodyStart), '/*')) {
                        return [[], self::VERSIONED_COMMENT_BOUNDARY_VIOLATION];
                    }

                    $ranges[] = [$opener[0], $offset + 2];
                    $versioned = null;
                }

                continue;
            }

            if ($versioned !== null) {
                return [[], self::VERSIONED_COMMENT_BOUNDARY_VIOLATION];
            }

            if (! preg_match('/^\/\*!(\d*)$/', $text, $match)) {
                continue;
            }

            $opener = [$offset, $offset + strlen($text)];

            if ($match[1] !== '') {
                if (! in_array(strlen($match[1]), $versionLengths, true)) {
                    return [[], "A version-conditional comment versioned \"{$match[1]}\" is not allowed: the server and QueryProxy read a version of that many digits differently."];
                }

                if ($openers !== []) {
                    return [[], self::VERSIONED_COMMENT_BOUNDARY_VIOLATION];
                }

                $versions[(int) $match[1]] = true;
                $versioned = $opener;
            }

            $openers[] = $opener;
        }

        if ($versioned !== null) {
            return [[], 'A block comment that is never closed is not allowed.'];
        }

        if (count($versions) > 1) {
            return [[], 'Version-conditional comments ("/*!NNNNN ... */") with different versions in one request are not allowed: QueryProxy checks the SQL with every such comment run and with every one skipped.'];
        }

        return [$ranges, null];
    }

    /**
     * Settle a request that holds a version-conditional comment: the guard
     * judged it with the comment bodies run ($executed), and the server may
     * skip them instead ($skipped, see skippedCommentReading()). It passes
     * only when the skipped reading passes on its own and differs from the
     * executed one by nothing but the skipped text: the same transaction
     * shape and statement count, no statement that writes or changes the
     * schema only in the skipped reading, and, statement by statement, the
     * same prepared text once the comments are taken out of the executed
     * one. A LIMIT the guard added or clamped inside a comment body would
     * otherwise be lost when the server skips it.
     *
     * One difference is let through: the default LIMIT the skipped reading
     * adds to a statement that the executed reading writes with. A write is
     * not given a default LIMIT, and a request holding one goes through the
     * write path whichever reading the server picks.
     *
     * The text that runs is the executed reading's, version conditions
     * included, so the server picks between two readings the guard has both
     * accepted and the request keeps its meaning.
     */
    private function reconcileSkippedReading(InspectionResult $executed, InspectionResult $skipped, DbDriver $driver): InspectionResult
    {
        $skippedNote = 'The server skips a version-conditional comment ("/*!NNNNN ... */") when its own version is lower';

        if (! $skipped->passes()) {
            return new InspectionResult($executed->statements, $executed->isTransaction, array_map(
                fn (string $violation): string => "{$skippedNote}, and the SQL without the comment is not allowed: {$violation}",
                $skipped->violations,
            ));
        }

        $differs = $executed->isTransaction !== $skipped->isTransaction
            || count($executed->statements) !== count($skipped->statements);

        foreach ($differs ? [] : $executed->statements as $index => $statement) {
            if (! $this->readsSameWhenSkipped($statement, $skipped->statements[$index], $driver)) {
                $differs = true;

                break;
            }
        }

        if ($differs) {
            return new InspectionResult($executed->statements, $executed->isTransaction, [
                "{$skippedNote}, and QueryProxy cannot prepare the SQL so that it reads the same with and without the comment.",
            ]);
        }

        return $executed;
    }

    /**
     * Whether a statement of the executed reading, with its version-conditional
     * comments skipped, is the matching statement of the skipped reading (see
     * reconcileSkippedReading()).
     */
    private function readsSameWhenSkipped(StatementInfo $executed, StatementInfo $skipped, DbDriver $driver): bool
    {
        if (($skipped->type === StatementType::Write && $executed->type === StatementType::Read)
            || ($skipped->isDdl && ! $executed->isDdl)) {
            return false;
        }

        [$withoutComments, $violation] = $this->skippedCommentReading($executed->preparedSql, $driver);

        if ($violation !== null) {
            return false;
        }

        $text = $this->collapseWhitespace($withoutComments ?? $executed->preparedSql);

        return $text === $this->collapseWhitespace($skipped->preparedSql)
            || ($skipped->limitInjected && $executed->type === StatementType::Write && $text === $this->collapseWhitespace($skipped->sql));
    }

    private function collapseWhitespace(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    /**
     * Every lexer token with the byte offset it starts at, from one lexer
     * pass. The token texts add up to the SQL, so the offsets are summed;
     * should they ever not, each one is converted from the lexer position.
     *
     * @param  ?array<int, Token>  $tokens  the lexer's tokens for $sql, when already at hand
     * @return list<array{0: Token, 1: int}>
     */
    private function tokensWithByteOffsets(string $sql, ?array $tokens = null): array
    {
        $tokens = array_values($tokens ?? $this->lexTokens($sql));
        $withOffsets = [];
        $offset = 0;

        foreach ($tokens as $token) {
            $withOffsets[] = [$token, $offset];
            $offset += strlen((string) $token->token);
        }

        if ($offset === strlen($sql)) {
            return $withOffsets;
        }

        return array_map(fn (Token $token): array => [$token, $this->tokenByteOffset($sql, $token->position)], $tokens);
    }

    /**
     * SQLite and SQL Server read comments differently from the lexer: "--"
     * starts a comment there whatever follows it ("--x", "1--1"), SQL Server
     * nests block comments, and "#" starts no comment at all. Where the
     * readings differ, the guard would skip text the server runs — "--x" on
     * one line hides nothing from the server but shifts every token after it
     * for the guard, and "#" hides the rest of the line from the guard only —
     * so the SQL is refused instead.
     *
     * On SQL Server a "#" outside strings and comments never reaches this
     * check: it starts a temporary table name and is masked beforehand (see
     * maskTemporaryTableMarkers()).
     */
    private function dialectCommentViolation(string $sql, ?DbDriver $driver): ?string
    {
        if ($driver !== DbDriver::Sqlite && $driver !== DbDriver::Sqlsrv) {
            return null;
        }

        $tokens = $this->lexTokens($sql);
        $dialect = $driver === DbDriver::Sqlite ? 'SQLite' : 'SQL Server';

        foreach ($tokens as $index => $token) {
            $text = (string) $token->token;

            if ($token->type === TokenType::Comment) {
                if (str_starts_with($text, '#')) {
                    return "\"#\" is not a comment in {$dialect}; QueryProxy cannot read SQL that uses it outside a string. Start a comment with \"-- \" instead.";
                }

                if ($driver === DbDriver::Sqlsrv && str_starts_with($text, '/*') && str_contains(substr($text, 2), '/*')) {
                    return 'A nested block comment is not allowed through QueryProxy: SQL Server and QueryProxy close it at different places.';
                }

                continue;
            }

            if (in_array($token->type, [TokenType::String, TokenType::Symbol, TokenType::Whitespace], true)) {
                continue;
            }

            $next = $tokens[$index + 1] ?? null;

            if (str_contains($text, '--') || ($text === '-' && $next !== null && str_starts_with((string) $next->token, '-'))) {
                return "\"--\" not followed by a space is not allowed through QueryProxy: {$dialect} reads it as a comment and QueryProxy does not. Write \"-- \" for a comment.";
            }
        }

        return null;
    }

    /**
     * Dollar quoting is PostgreSQL syntax. The guard's dollar-quote scanner is
     * driver-blind, so on any other server a "$$ ... $$" pair would hide the
     * text between the tags from the guard (as one string) while the server
     * runs it: "$$" is a plain identifier in MySQL, and "[$$]" a quoted name
     * in SQL Server and SQLite. SQL that the scanner reads a dollar-quoted
     * string in is therefore refused on those servers.
     */
    private function dollarQuoteDialectViolation(string $sql, ?DbDriver $driver): ?string
    {
        if ($driver === null || $driver === DbDriver::Pgsql) {
            return null;
        }

        if ($this->dollarQuotedRanges($sql) === []) {
            return null;
        }

        return 'Dollar-quoted text ($$ ... $$ or $tag$ ... $tag$) is PostgreSQL syntax and is not allowed on this connection: QueryProxy would read it as one string while the server does not.';
    }

    /**
     * Locate the strings, quoted names and comments of a SQL Server or SQLite
     * statement with that server's own lexical rules, and on SQL Server
     * replace every "#" outside a string or a comment with a sentinel
     * character the lexer reads as part of a name.
     *
     * In SQL Server "#" and "##" start a temporary table name, while the
     * MySQL-dialect lexer reads "#" as a comment to the end of the line; left
     * as it is, "FROM #t WHERE id = 1" would hide its WHERE clause (and
     * anything after it) from the guard. DEL (0x7F) takes its place: the
     * lexer treats it as a name character, it is a single byte so no offset
     * moves, and SQL that already contains one is refused so every DEL seen
     * later is known to be a masked "#". The sentinel is turned back into
     * "#" in everything inspect() returns.
     *
     * @return array{0: string, 1: ?array{quoted: list<array{0: int, 1: int}>, comments: list<array{0: int, 1: int}>, brackets: list<array{0: int, 1: int}>}, 2: ?string}
     */
    private function maskTemporaryTableMarkers(string $sql, DbDriver $driver): array
    {
        $dialect = $driver === DbDriver::Sqlite ? 'SQLite' : 'SQL Server';

        if ($driver === DbDriver::Sqlsrv && str_contains($sql, self::TEMPORARY_TABLE_SENTINEL)) {
            return [$sql, null, 'A DEL control character (0x7F) is not allowed in SQL Server SQL through QueryProxy.'];
        }

        $ranges = $this->dialectLexicalRanges($sql, $driver);

        if ($ranges === null) {
            return [$sql, null, "A string, quoted name or comment that is never closed (or a line comment broken by a lone carriage return) is not allowed: QueryProxy cannot tell where {$dialect} ends it."];
        }

        if ($driver !== DbDriver::Sqlsrv || ! str_contains($sql, '#')) {
            return [$sql, $ranges, null];
        }

        $chars = mb_str_split($sql);
        $opaque = array_merge($ranges['quoted'], $ranges['comments']);
        usort($opaque, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        foreach ($chars as $position => $char) {
            if ($char === '#' && ! $this->isWithinSortedRanges($position, $opaque)) {
                $chars[$position] = self::TEMPORARY_TABLE_SENTINEL;
            }
        }

        return [implode('', $chars), $ranges, null];
    }

    private function unmaskTemporaryTableMarkers(string $text): string
    {
        return str_replace(self::TEMPORARY_TABLE_SENTINEL, '#', $text);
    }

    /**
     * Character ranges of the quoted strings and names, the comments and the
     * bracketed names of a statement, read with SQL Server's or SQLite's
     * lexical rules: '...' and "..." double their quote and take no backslash
     * escape, a backtick quotes a name in SQLite only, [...] quotes a name
     * (SQL Server doubles "]" inside it, SQLite closes on the first one),
     * "--" starts a comment wherever it appears and runs to the line feed,
     * and block comments nest in SQL Server.
     *
     * Null when any of them is never closed, or when a line comment holds a
     * carriage return that is not part of a CR LF pair (where the comment
     * ends is then not certain).
     *
     * Each list holds disjoint ranges ordered by their start, and callers
     * binary-search them (isWithinSortedRanges, firstRangeEndingAfter), so
     * any change here must keep that order.
     *
     * @return array{quoted: list<array{0: int, 1: int}>, comments: list<array{0: int, 1: int}>, brackets: list<array{0: int, 1: int}>}|null
     */
    private function dialectLexicalRanges(string $sql, DbDriver $driver): ?array
    {
        $chars = mb_str_split($sql);
        $length = count($chars);
        $sqlsrv = $driver === DbDriver::Sqlsrv;
        $ranges = ['quoted' => [], 'comments' => [], 'brackets' => []];
        $offset = 0;

        while ($offset < $length) {
            $char = $chars[$offset];
            $next = $chars[$offset + 1] ?? '';

            if ($char === '-' && $next === '-') {
                $end = $offset + 2;

                while ($end < $length && $chars[$end] !== "\n") {
                    if ($chars[$end] === "\r" && ($chars[$end + 1] ?? "\n") !== "\n") {
                        return null;
                    }

                    $end++;
                }

                $ranges['comments'][] = [$offset, $end];
                $offset = $end;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $depth = 1;
                $end = $offset + 2;

                while ($end < $length && $depth > 0) {
                    $pair = $chars[$end].($chars[$end + 1] ?? '');

                    if ($sqlsrv && $pair === '/*') {
                        $depth++;
                        $end += 2;
                    } elseif ($pair === '*/') {
                        $depth--;
                        $end += 2;
                    } else {
                        $end++;
                    }
                }

                if ($depth > 0) {
                    return null;
                }

                $ranges['comments'][] = [$offset, $end];
                $offset = $end;

                continue;
            }

            $closer = match (true) {
                $char === "'", $char === '"' => $char,
                $char === '`' && ! $sqlsrv => '`',
                $char === '[' => ']',
                default => null,
            };

            if ($closer === null) {
                $offset++;

                continue;
            }

            $doubles = $closer !== ']' || $sqlsrv;
            $end = $offset + 1;
            $closed = false;

            while ($end < $length) {
                if ($chars[$end] !== $closer) {
                    $end++;

                    continue;
                }

                if ($doubles && ($chars[$end + 1] ?? '') === $closer) {
                    $end += 2;

                    continue;
                }

                $end++;
                $closed = true;

                break;
            }

            if (! $closed) {
                return null;
            }

            $ranges[$char === '[' ? 'brackets' : 'quoted'][] = [$offset, $end];
            $offset = $end;
        }

        return $ranges;
    }

    /**
     * Hold the lexer's tokens against the strings, names and comments the
     * server itself reads (dialectLexicalRanges()). Every string or comment
     * token, and every token carrying a quote character, must coincide with
     * a server range of the same kind; no token may straddle the edge of a
     * server range; and inside a bracketed name only plain name tokens may
     * appear. Anything else means the guard and the server split the text
     * differently — "'a\' EXEC ..." is one string to the lexer, which takes a
     * backslash escape, but a string and an EXEC to the server — and the SQL
     * is refused.
     *
     * Positions are counted in characters, as the lexer counts them; a line
     * comment is compared without its trailing whitespace.
     *
     * @param  array{quoted: list<array{0: int, 1: int}>, comments: list<array{0: int, 1: int}>, brackets: list<array{0: int, 1: int}>}  $ranges
     */
    private function dialectLexicalViolation(string $sql, array $ranges, DbDriver $driver): ?string
    {
        $dialect = $driver === DbDriver::Sqlite ? 'SQLite' : 'SQL Server';
        $violation = "QueryProxy reads a string, quoted name or comment in this SQL differently from {$dialect} (a backslash escape, a backtick, or a quote inside a [bracketed] name), so it cannot inspect it safely.";

        $serverRanges = [];
        $chars = $ranges['comments'] === [] ? [] : mb_str_split($sql);

        foreach (['quoted', 'comments', 'brackets'] as $kind) {
            foreach ($ranges[$kind] as [$start, $end]) {
                if ($kind === 'comments') {
                    // rtrim()'s whitespace is all single-byte, so trimming
                    // characters off the end trims the same text.
                    while ($end > $start && str_contains(" \t\n\r\0\x0B", $chars[$end - 1])) {
                        $end--;
                    }
                }

                $serverRanges[] = [$start, $end, $kind];
            }
        }

        // The server ranges never overlap, so ordered by start they are
        // ordered by end too, and a token's overlapping ranges are one run.
        usort($serverRanges, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $rangeCount = count($serverRanges);

        foreach ($this->lexTokens($sql) as $token) {
            $text = (string) $token->token;

            if ($token->position === null || $text === '') {
                continue;
            }

            $isComment = $token->type === TokenType::Comment;
            $start = $token->position;
            $end = $start + mb_strlen($isComment ? rtrim($text) : $text);
            $isQuoted = $token->type === TokenType::String || strpbrk($text, '\'"`') !== false;
            $matched = false;

            for ($index = $this->firstRangeEndingAfter($serverRanges, $start); $index < $rangeCount; $index++) {
                [$rangeStart, $rangeEnd, $kind] = $serverRanges[$index];

                if ($end <= $rangeStart) {
                    break;
                }

                if ($start === $rangeStart && $end === $rangeEnd
                    && (($kind === 'comments' && $isComment) || ($kind === 'quoted' && $isQuoted && ! $isComment))) {
                    $matched = true;

                    continue;
                }

                if ($kind === 'brackets' && $start >= $rangeStart && $end <= $rangeEnd && ! $isComment && ! $isQuoted) {
                    continue;
                }

                return $violation;
            }

            if (($isComment || $isQuoted) && ! $matched) {
                return $violation;
            }
        }

        return null;
    }

    /**
     * Reject a UESCAPE clause outright on PostgreSQL and on an unknown driver.
     *
     * UESCAPE picks the escape character of a U&"..." identifier or U&'...'
     * string, and PostgreSQL accepts it from any string constant (E'..',
     * $$..$$, ...). Decoding every such spelling is a surface the guard does
     * not need to carry: the default backslash escape covers every name.
     */
    private function unicodeEscapeClauseViolation(string $sql, ?DbDriver $driver): ?string
    {
        if ($driver !== null && $driver !== DbDriver::Pgsql) {
            return null;
        }

        foreach ($this->significantTokens($sql) as $token) {
            if (in_array($token->type, [TokenType::None, TokenType::Keyword], true)
                && in_array('UESCAPE', $this->tokenWords($token), true)) {
                return 'A UESCAPE clause is not allowed through QueryProxy; write Unicode escapes with the default backslash escape.';
            }
        }

        return null;
    }

    /**
     * Refuse, on PostgreSQL and on an unknown driver, the backslashes and
     * "#" signs the lexer reads differently from PostgreSQL.
     *
     * The lexer reads SQL as MySQL does: a backslash escapes the next
     * character inside any string or quoted name, and "#" starts a comment.
     * In PostgreSQL (standard_conforming_strings on) a backslash is a plain
     * character outside an E'...' string, and "#" is the bitwise XOR
     * operator. "SELECT 'a\', pg_read_file('/etc/passwd') --'" is one string
     * to the lexer but a call to PostgreSQL, and "SELECT 1 # 1; DELETE ..."
     * hides a second statement in a comment the server does not see.
     *
     * Fail-closed, with PostgreSQL's own reading (postgresLexicalRanges()):
     *  - a backslash is allowed only in a comment, a dollar-quoted string, an
     *    E'...' string, or a U& string or identifier whose escapes are all
     *    well formed (a U& escape never stands before the closing quote, so
     *    both sides end it at the same place);
     *  - a "#" is allowed only in a comment, a dollar-quoted string, a string
     *    or a quoted identifier.
     */
    private function postgresLexicalViolation(string $sql, ?DbDriver $driver): ?string
    {
        if ($driver !== null && $driver !== DbDriver::Pgsql) {
            return null;
        }

        $hasBackslash = str_contains($sql, '\\');
        $hasHash = str_contains($sql, '#');

        if (! $hasBackslash && ! $hasHash) {
            return null;
        }

        $postgres = $this->postgresLexicalRanges($sql);

        if ($postgres === null) {
            return 'A block comment that is never closed is not allowed.';
        }

        $target = $driver === null ? 'the target database is unknown' : 'the target is PostgreSQL';
        $backslashAdvice = $driver === null
            ? 'Select a connection so the SQL is read in its dialect.'
            : "Use an E'...' string or a dollar-quoted string instead.";
        $opaque = array_merge($postgres['comments'], $postgres['quoted']);
        $escaping = $opaque;

        foreach ($postgres['strings'] as [$start, $end, $kind]) {
            $opaque[] = [$start, $end];

            if ($kind === 'escape' || ($kind === 'unicode' && $this->hasWellFormedUnicodeEscapes(substr($sql, $start + 1, $end - $start - 2)))) {
                $escaping[] = [$start, $end];
            }
        }

        if ($hasBackslash && ! $this->allWithinRanges($this->bytePositions($sql, '\\'), $escaping)) {
            return "QueryProxy reads a backslash in this SQL differently from PostgreSQL (outside an E'...' string it does not escape a quote), so it cannot inspect it safely when {$target}. {$backslashAdvice}";
        }

        if ($hasHash && ! $this->allWithinRanges($this->bytePositions($sql, '#'), $opaque)) {
            return "\"#\" is an operator in PostgreSQL, not a comment; QueryProxy cannot read SQL that uses it outside a string or a comment when {$target}. Start a comment with \"-- \" instead.";
        }

        return null;
    }

    /**
     * Byte offsets, ascending, of every occurrence of a single byte.
     *
     * @return list<int>
     */
    private function bytePositions(string $sql, string $byte): array
    {
        $positions = [];
        $offset = 0;

        while (($offset = strpos($sql, $byte, $offset)) !== false) {
            $positions[] = $offset;
            $offset++;
        }

        return $positions;
    }

    /**
     * True when every backslash in the body of a U& string or identifier
     * starts an escape PostgreSQL accepts with the default escape character:
     * "\\", "\XXXX" or "\+XXXXXX". A malformed escape (a backslash before
     * the closing quote among them) is an error in PostgreSQL, but the lexer
     * would read it as escaping the next character.
     */
    private function hasWellFormedUnicodeEscapes(string $body): bool
    {
        $offset = 0;

        while (($offset = strpos($body, '\\', $offset)) !== false) {
            if (($body[$offset + 1] ?? '') === '\\') {
                $offset += 2;

                continue;
            }

            if (! preg_match('/\\\\(?:[0-9A-Fa-f]{4}|\+[0-9A-Fa-f]{6})/A', $body, $match, 0, $offset)) {
                return false;
            }

            $offset += strlen($match[0]);
        }

        return true;
    }

    /**
     * True when the lexer reports a block comment with no closing marker.
     * The opening marker of a MySQL executable comment is its own token and
     * is closed by a separate token, so it is not one. A MariaDB "/*M!"
     * comment is one token to the lexer, closing marker included, so a bare
     * "/*M!" or "/*M!100000" token is an unclosed comment like any other.
     */
    private function hasUnterminatedBlockComment(string $sql): bool
    {
        foreach ($this->lexTokens($sql) as $token) {
            if ($token->type !== TokenType::Comment || ! str_starts_with($token->token, '/*')) {
                continue;
            }

            if (preg_match('/^\/\*!\d*$/', $token->token)) {
                continue;
            }

            if (strlen($token->token) < 4 || ! str_ends_with($token->token, '*/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Byte ranges of the comments and of the dollar-quoted strings of a
     * statement, located with PostgreSQL's lexical rules: block comments
     * nest, "--" starts a comment anywhere and runs to the next CR or LF,
     * '...' strings double their quote (and an E'...' string also takes
     * backslash escapes), "..." identifiers double theirs, and $tag$ ... $tag$
     * quotes everything up to the same tag. Null when a block comment is
     * never closed.
     *
     * Plain '...' strings are read with standard_conforming_strings on, the
     * PostgreSQL default since 9.1.
     *
     * "quoted" holds the dollar-quoted strings; "strings" holds every '...'
     * string and "..." identifier, quotes included, with how PostgreSQL reads
     * a backslash in it: "escape" for an E'...' string, "unicode" for a U&
     * string or identifier, "plain" for the rest.
     *
     * With $openCommentRunsToEnd a block comment that is never closed is
     * reported as running to the end of the text instead of yielding null.
     *
     * @return array{comments: list<array{0: int, 1: int}>, quoted: list<array{0: int, 1: int}>, strings: list<array{0: int, 1: int, 2: string}>}|null
     */
    private function postgresLexicalRanges(string $sql, bool $openCommentRunsToEnd = false): ?array
    {
        $comments = [];
        $quoted = [];
        $strings = [];
        $length = strlen($sql);
        $offset = 0;

        while ($offset < $length) {
            $char = $sql[$offset];
            $next = $sql[$offset + 1] ?? '';
            $previous = $offset > 0 ? $sql[$offset - 1] : '';

            if ($char === '-' && $next === '-') {
                $end = $offset + strcspn($sql, "\r\n", $offset);
                $comments[] = [$offset, $end];
                $offset = $end;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $depth = 1;
                $cursor = $offset + 2;

                while ($cursor < $length && $depth > 0) {
                    $pair = substr($sql, $cursor, 2);

                    if ($pair === '/*') {
                        $depth++;
                        $cursor += 2;
                    } elseif ($pair === '*/') {
                        $depth--;
                        $cursor += 2;
                    } else {
                        $cursor++;
                    }
                }

                if ($depth > 0 && ! $openCommentRunsToEnd) {
                    return null;
                }

                $comments[] = [$offset, min($cursor, $length)];
                $offset = $cursor;

                continue;
            }

            if ($char === "'" || $char === '"') {
                $escapes = $char === "'"
                    && ($previous === 'E' || $previous === 'e')
                    && ! $this->isPostgresIdentifierCharacter($offset > 1 ? $sql[$offset - 2] : '');
                $unicode = $previous === '&'
                    && $offset > 1 && ($sql[$offset - 2] === 'U' || $sql[$offset - 2] === 'u')
                    && ! $this->isPostgresIdentifierCharacter($offset > 2 ? $sql[$offset - 3] : '');
                $end = $this->skipPostgresQuoted($sql, $offset, $escapes);
                $strings[] = [$offset, $end, $escapes ? 'escape' : ($unicode ? 'unicode' : 'plain')];
                $offset = $end;

                continue;
            }

            if ($char === '$'
                && ! $this->isPostgresIdentifierCharacter($previous)
                && preg_match('/\$(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?\$/A', $sql, $matches, 0, $offset)) {
                $tag = $matches[0];
                $close = strpos($sql, $tag, $offset + strlen($tag));

                // An unterminated dollar quote is a syntax error in
                // PostgreSQL; the rest of the statement is its body.
                $end = $close === false ? $length : $close + strlen($tag);
                $quoted[] = [$offset, $end];
                $offset = $end;

                continue;
            }

            $offset++;
        }

        return ['comments' => $comments, 'quoted' => $quoted, 'strings' => $strings];
    }

    /**
     * Advance past a PostgreSQL '...' or "..." token: the quote is escaped by
     * doubling it, and by a backslash only in an E'...' string.
     */
    private function skipPostgresQuoted(string $sql, int $start, bool $backslashEscapes): int
    {
        $quote = $sql[$start];
        $length = strlen($sql);
        $offset = $start + 1;

        while ($offset < $length) {
            if ($backslashEscapes && $sql[$offset] === '\\') {
                $offset += 2;

                continue;
            }

            if ($sql[$offset] === $quote) {
                if (($sql[$offset + 1] ?? '') === $quote) {
                    $offset += 2;

                    continue;
                }

                return $offset + 1;
            }

            $offset++;
        }

        return $length;
    }

    private function isPostgresIdentifierCharacter(string $char): bool
    {
        return $char !== '' && (ctype_alnum($char) || $char === '_' || $char === '$' || ord($char) >= 0x80);
    }

    /**
     * Byte ranges of the comments the lexer reports, in the form
     * postgresLexicalRanges() uses. A "#" comment is left out (see
     * resolveComments()), and a trailing CR is not part of a line comment,
     * since PostgreSQL ends the comment there too.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function lexerCommentRanges(string $sql): array
    {
        $ranges = [];

        foreach ($this->lexTokens($sql) as $token) {
            if ($token->type !== TokenType::Comment || $token->position === null || str_starts_with($token->token, '#')) {
                continue;
            }

            $text = str_starts_with($token->token, '--') ? rtrim($token->token, "\r") : $token->token;
            $ranges[] = [$token->position, $token->position + strlen($text)];
        }

        return $ranges;
    }

    /**
     * Replace every byte of the given ranges with a space, keeping line
     * breaks, so offsets and line numbers stay where they were.
     *
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    private function blankRanges(string $sql, array $ranges): string
    {
        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $blanked = '';
        $cursor = 0;

        foreach ($ranges as [$start, $end]) {
            $start = max($start, $cursor);

            if ($end <= $start) {
                continue;
            }

            $blanked .= substr($sql, $cursor, $start - $cursor)
                .preg_replace('/[^\r\n]/', ' ', substr($sql, $start, $end - $start));
            $cursor = $end;
        }

        return $blanked.substr($sql, $cursor);
    }

    /**
     * The lexer's tokens for $sql. Within an inspect() call the same text is
     * read by many checks, so its tokens are worked out once and shared; the
     * lexer is a pure function of the text and no check modifies a token.
     *
     * @return list<Token>
     */
    private function lexTokens(string $sql): array
    {
        if ($this->inspectDepth === 0) {
            return $this->lex($sql);
        }

        return $this->lexedTokens[$sql] ??= $this->lex($sql);
    }

    /**
     * Runs the lexer on $sql. No check reads the lexer's errors, so they are
     * not kept: each one would otherwise carry its own exception and
     * backtrace, hundreds of megabytes on a maximum-size request the lexer
     * stumbles over character after character (an unclosed "[", say).
     * Protected only so that a test can count the lexer passes.
     *
     * @return list<Token>
     */
    protected function lex(string $sql): array
    {
        $lexer = new class($sql) extends Lexer
        {
            public function error(string $msg, string $str = '', int $pos = 0, int $code = 0): void {}
        };

        return $lexer->list->tokens;
    }

    /**
     * Turn a lexer token position into a byte offset into the same SQL.
     *
     * The lexer reads multibyte SQL as a UtfString, so its token positions
     * count characters, while substr() and the dollar-quoted ranges count
     * bytes. A multibyte comment before the token ('é' is two bytes) would
     * otherwise shift every cut that follows it.
     */
    private function tokenByteOffset(string $sql, ?int $position): int
    {
        $position = (int) $position;

        if ($this->byteOffsetsSql !== $sql) {
            $this->byteOffsetsSql = $sql;
            $this->byteOffsets = $this->characterByteOffsets($sql);
        }

        if ($this->byteOffsets === null) {
            return $position;
        }

        if ($position < 0 || $this->byteOffsets === false) {
            return strlen(mb_substr($sql, 0, $position, 'UTF-8'));
        }

        return $this->byteOffsets[$position] ?? strlen($sql);
    }

    /**
     * The byte offset of every character of $sql, and the byte length after
     * the last one: null when every character is one byte (the offsets are
     * the positions themselves), false when $sql is not valid UTF-8 (the
     * offsets are then left to mb_substr()).
     *
     * @return list<int>|false|null
     */
    private function characterByteOffsets(string $sql): array|false|null
    {
        if (strlen($sql) === mb_strlen($sql, 'UTF-8')) {
            return null;
        }

        if (! mb_check_encoding($sql, 'UTF-8')) {
            return false;
        }

        $offsets = [];
        $offset = 0;

        foreach (mb_str_split($sql, 1, 'UTF-8') as $char) {
            $offsets[] = $offset;
            $offset += strlen($char);
        }

        $offsets[] = $offset;

        return $offsets;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    private function isWithinRange(int $position, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($position >= $start && $position < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * isWithinRange() for ranges that do not overlap and are ordered by
     * start, in logarithmic time.
     *
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    private function isWithinSortedRanges(int $position, array $ranges): bool
    {
        $index = $this->firstRangeEndingAfter($ranges, $position);

        return $index < count($ranges) && $ranges[$index][0] <= $position;
    }

    /**
     * The index of the first range that ends after $position (count($ranges)
     * when none does), found by binary search: the ranges must not overlap
     * and must be ordered by start, so their ends are ordered too.
     *
     * @param  list<array{0: int, 1: int, 2?: string}>  $ranges
     */
    private function firstRangeEndingAfter(array $ranges, int $position): int
    {
        $low = 0;
        $high = count($ranges);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($ranges[$middle][1] <= $position) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * True when a data-modifying keyword appears anywhere in the statement,
     * at any nesting depth — CTE bodies are always parenthesised, so a
     * depth-0-only scan would miss WITH x AS (INSERT ...) entirely.
     */
    private function hasWriteKeyword(string $sql): bool
    {
        $tokens = $this->lexTokens($sql);

        foreach ($tokens as $token) {
            if (in_array($token->type, [TokenType::Whitespace, TokenType::Comment, TokenType::String], true)) {
                continue;
            }

            if (in_array(strtoupper($token->token), ['INSERT', 'UPDATE', 'DELETE', 'MERGE'], true)) {
                return true;
            }
        }

        return false;
    }

    private function isCommentOnly(string $sql): bool
    {
        $stripped = preg_replace('/--[^\n]*|#[^\n]*|\/\*.*?\*\//s', '', $sql);

        return trim((string) $stripped) === '';
    }
}
