<?php

namespace App\Services\Sql;

use App\Enums\DbDriver;
use App\Enums\StatementType;
use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\DeleteStatement;
use PhpMyAdmin\SqlParser\Statements\ExplainStatement;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Statements\ShowStatement;
use PhpMyAdmin\SqlParser\Statements\UpdateStatement;
use PhpMyAdmin\SqlParser\Token;
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
 * smuggle a forbidden statement past the anchored patterns.
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
 *  - SET is judged by the variable name, and only in its persistent scopes
 *    (GLOBAL / PERSIST / PERSIST_ONLY, in both the keyword and the "@@scope."
 *    spelling). Variables that name a file path, load code or switch a
 *    protection off are rejected; max_connections, wait_timeout, sql_mode and
 *    the rest pass, and SESSION-scoped writes are never touched.
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
     * Procedural languages whose functions execute outside the database's
     * permission system — installing one, or asking a DO block to run in one,
     * is equivalent to shell access on the database host. The PostgreSQL
     * convention is a trailing "u" for untrusted, but matching on that shape
     * would also catch unrelated extensions, so the names are enumerated.
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
     * Server variables whose persistent (GLOBAL / PERSIST) value names a file
     * path, loads code into the server process, or switches a protection off.
     * Matching is on the exact name, case-insensitively: "general_log" and
     * "general_log_file" are two separate doors and both are listed.
     *
     * Configuration is merged on top of this list and can only extend it;
     * these entries are the floor.
     *
     * @var list<string>
     */
    public const DANGEROUS_VARIABLES = [
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
    ];

    /**
     * Functions that control the server or other sessions, or open a channel
     * to another server. Calling one is rejected outright. A trailing "*"
     * matches every name with that prefix.
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
     * SET scopes that survive the session and therefore change the server for
     * everyone. SESSION / LOCAL writes are deliberately not guarded.
     *
     * @var list<string>
     */
    private const PERSISTENT_SCOPES = ['GLOBAL', 'PERSIST', 'PERSIST_ONLY'];

    private const FORBIDDEN_PATTERNS = [
        '/^DROP\s+(DATABASE|SCHEMA)\b/i' => 'DROP DATABASE is not allowed through QueryProxy.',
        '/^GRANT\b/i' => 'GRANT statements are not allowed through QueryProxy.',
        '/^REVOKE\b/i' => 'REVOKE statements are not allowed through QueryProxy.',
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
        $violations = [];

        [$sql, $lexicalViolation] = $this->resolveComments($sql, $driver);
        $lexicalViolation ??= $this->unicodeEscapeClauseViolation($sql, $driver);

        if ($lexicalViolation !== null) {
            return new InspectionResult([], false, [$lexicalViolation]);
        }

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
            [$info, $statementViolations] = $this->analyzeStatement($statementSql, $index + 1, $driver);
            $infos[] = $info;
            $violations = array_merge($violations, $statementViolations);
        }

        return new InspectionResult($infos, $isTransaction, array_values(array_unique($violations)));
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
     * @return list<string>
     */
    public function splitStatements(string $sql): array
    {
        $lexer = new Lexer($sql);
        $quoted = $this->dollarQuotedRanges($sql);

        $segments = [];
        $start = 0;

        foreach ($lexer->list->tokens as $token) {
            if ($token->type === TokenType::Delimiter && $token->token !== '' && $token->position !== null) {
                if ($this->isWithinRange($token->position, $quoted)) {
                    continue;
                }

                $segments[] = substr($sql, $start, $token->position - $start);
                $start = $token->position + strlen($token->token);
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
        $tokens = (new Lexer($maskDollarQuoted ? $this->maskDollarQuoted($sql) : $sql))->list->tokens;
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

        foreach ($this->targetedViolations($sql) as $message) {
            $violations[] = "{$label}: {$message}";
        }

        $tokens = $this->significantTokens($sql);

        // The keyword is read from the token stream, never from whitespace:
        // "EXPLAIN(ANALYZE)DELETE", "SELECT*INTO t2" and "(SELECT ...)" all
        // start with a keyword that a split on spaces would not see.
        [$keyword, $wrappingParens] = $this->leadingKeyword($tokens);

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

        $functionWrites = false;

        if ($executes) {
            [$functionViolations, $functionWrites] = $this->functionCallEffects($tokens);

            foreach ($functionViolations as $message) {
                $violations[] = "{$label}: {$message}";
            }
        }

        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;
        $parsed = $statement !== null && $parser->errors === [];

        $type = $this->classify($statement, $keyword, $sql, $parsed);
        $isDdl = in_array($keyword, self::DDL_KEYWORDS, true);

        // SELECT ... INTO <table> creates a table: a DDL write that must
        // neither be labelled a read nor have a LIMIT appended.
        if (in_array($keyword, ['SELECT', 'WITH'], true) && $this->selectsIntoTable($tokens)) {
            $type = StatementType::Write;
            $isDdl = true;
        }

        if ($functionWrites) {
            $type = StatementType::Write;
        }

        // WHERE guard for UPDATE / DELETE.
        if ($statement instanceof UpdateStatement || $statement instanceof DeleteStatement) {
            if ($statement->where === null || $statement->where === []) {
                $violations[] = "{$label}: ".strtoupper($keyword).' without a WHERE clause is not allowed.';
            }
        } elseif (! $parsed && in_array($keyword, ['UPDATE', 'DELETE'], true)) {
            if (! preg_match('/\bWHERE\b/i', $normalized)) {
                $violations[] = "{$label}: {$keyword} without a WHERE clause is not allowed.";
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
     * The original SQL is lexed (not its dollar-masked form) so the inner
     * offset is a real byte offset into it; the option block never contains
     * dollar quoting, and the lexer reads left to right, so anything later in
     * the statement cannot shift the tokens of the block.
     *
     * @return array{resolved: bool, analyze: bool, innerOffset: int}
     */
    private function explainOptions(string $sql): array
    {
        $tokens = [];

        foreach ((new Lexer($sql))->list->tokens as $token) {
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

        $innerOffset = isset($tokens[$index]) ? (int) $tokens[$index]->position : strlen($sql);

        return ['resolved' => true, 'analyze' => $analyze, 'innerOffset' => $innerOffset];
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
                $violations[] = "{$name}() is not allowed through QueryProxy: it controls the server or other sessions, or opens a channel to another server.";
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
            return ["set_config('{$name}') is not allowed through QueryProxy: {$name} controls server-side file paths, code loading or a protection switch."];
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
        if ($statement instanceof SelectStatement
            || $statement instanceof ShowStatement
            || $statement instanceof ExplainStatement) {
            // A parsed SELECT can still smuggle a write inside a CTE body
            // (PostgreSQL WITH ... AS (INSERT ...)), so WITH is re-checked below.
            if (strtoupper($keyword) !== 'WITH') {
                return StatementType::Read;
            }
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

        $tokens = (new Lexer($sql))->list->tokens;

        $depth = 0;
        $limitIndex = null;
        $forIndex = null;
        $hasFetch = false;
        $hasTop = false;

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

            if ($upper === 'LIMIT') {
                $limitIndex = $i;
            } elseif ($upper === 'FETCH') {
                $hasFetch = true;
            } elseif ($upper === 'TOP' && $driver === DbDriver::Sqlsrv) {
                $hasTop = true;
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
        if ($hasTop) {
            return $this->clampTopClause($sql, $hardLimit);
        }

        if ($limitIndex === null) {
            if ($driver === DbDriver::Sqlsrv) {
                // LIMIT is not valid T-SQL; the executor-level hard cap
                // bounds the result instead.
                return [$sql, false, false, []];
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
     * @return array{0: string, 1: bool, 2: bool, 3: list<string>}
     */
    private function clampTopClause(string $sql, int $hardLimit): array
    {
        if (! preg_match('/\bTOP\s*\(?\s*(\d+)\s*\)?/i', $sql, $matches, PREG_OFFSET_CAPTURE)) {
            return [$sql, false, false, [
                'Unsupported TOP clause; use TOP <n> with a plain row count.',
            ]];
        }

        $count = (int) $matches[1][0];

        if ($count <= $hardLimit) {
            return [$sql, false, false, []];
        }

        $prepared = substr($sql, 0, $matches[1][1])
            .$hardLimit
            .substr($sql, $matches[1][1] + strlen($matches[1][0]));

        return [$prepared, false, true, []];
    }

    /**
     * Run the targeted rules — the ones that judge a statement by the
     * dangerous part inside it rather than by its syntax.
     *
     * @return list<string>
     */
    private function targetedViolations(string $sql): array
    {
        $tokens = $this->significantTokens($sql);

        if ($tokens === []) {
            return [];
        }

        $keyword = strtoupper((string) $tokens[0]->value);

        if ($keyword === 'DO') {
            return $this->anonymousBlockViolations($sql, $tokens);
        }

        if ($keyword === 'SET') {
            return $this->serverVariableViolations($tokens);
        }

        if ($keyword === 'CREATE' && isset($tokens[1]) && strtoupper((string) $tokens[1]->value) === 'EXTENSION') {
            return $this->extensionViolations($tokens);
        }

        return [];
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
     * A DO block is judged by its LANGUAGE clause. Both spellings put the
     * clause outside the body — DO LANGUAGE plpgsql $$...$$ and
     * DO $$...$$ LANGUAGE plpgsql — and the body is masked to a plain literal
     * before tokenization, so body text cannot steer the scan. Every LANGUAGE
     * occurrence is checked rather than just the first: naming an untrusted
     * language anywhere in the statement is enough to reject it.
     *
     * No LANGUAGE clause at all means the PostgreSQL default, plpgsql, which
     * is a trusted language and passes.
     *
     * The body is then scanned as well, so that a DO block cannot carry a
     * statement the unconditional denylist refuses on its own.
     *
     * @param  list<Token>  $tokens
     * @return list<string>
     */
    private function anonymousBlockViolations(string $sql, array $tokens): array
    {
        $violations = [];

        for ($i = 1, $count = count($tokens); $i < $count; $i++) {
            if (strtoupper((string) $tokens[$i]->value) !== 'LANGUAGE' || ! isset($tokens[$i + 1])) {
                continue;
            }

            $language = $this->identifierValue($tokens[$i + 1]);

            if ($this->isUntrustedLanguage($language)) {
                $violations[] = "DO ... LANGUAGE {$language} is not allowed through QueryProxy: {$language} is an untrusted procedural language, so the block would run as the database superuser.";
            }
        }

        $body = $this->anonymousBlockBody($sql, $tokens);

        if ($body === null) {
            return $violations;
        }

        // The body is PL/pgSQL, read by PostgreSQL's scanner: its comments
        // nest, so they are blanked by those rules before the MySQL-first
        // lexer gets a chance to end one early.
        $ranges = $this->postgresLexicalRanges($body);

        if ($ranges === null) {
            $violations[] = 'A DO block whose body leaves a block comment open is not allowed.';

            return $violations;
        }

        return array_merge($violations, $this->forbiddenInBlockBody($this->blankRanges($body, $ranges['comments'])));
    }

    /**
     * The source text a DO block asks the server to execute: the contents of
     * its outermost dollar-quoted region, or of its single-quoted body when it
     * is written in that older form. Nested dollar quoting is left in place —
     * inside the body it is quoting, not framing, and the body scan wants to
     * see through it.
     *
     * @param  list<Token>  $tokens
     */
    private function anonymousBlockBody(string $sql, array $tokens): ?string
    {
        $ranges = $this->dollarQuotedRanges($sql);

        if ($ranges !== []) {
            [$start, $end] = $ranges[0];
            $region = substr($sql, $start, $end - $start);
            $tag = preg_match('/^\$[A-Za-z0-9_\x80-\xff]*\$/', $region, $matches) ? $matches[0] : '';

            return substr($region, strlen($tag), strlen($region) - 2 * strlen($tag));
        }

        foreach ($tokens as $token) {
            if ($token->type === TokenType::String) {
                return (string) $token->value;
            }
        }

        return null;
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
    private function forbiddenInBlockBody(string $body): array
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
                    $violations[$message] = "Inside the DO block body: {$message}";
                }
            }
        }

        return array_values($violations);
    }

    /**
     * SET is judged by the variable being written and by the scope it is
     * written in. Only the persistent scopes are guarded, in both the keyword
     * form (SET GLOBAL x = ...) and the variable form (SET @@GLOBAL.x = ...);
     * a SESSION-scoped write changes nothing for anyone else.
     *
     * When the statement carries a leading scope keyword it is applied to
     * every bare assignment in the list, which is the conservative reading:
     * servers differ on whether SET GLOBAL a = 1, b = 2 scopes b globally too,
     * and over-rejecting there is cheaper than guessing wrong.
     *
     * @param  list<Token>  $tokens
     * @return list<string>
     */
    private function serverVariableViolations(array $tokens): array
    {
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

        $violations = [];
        $dangerous = $this->dangerousVariables();

        foreach ($this->splitAssignments($tokens, $index) as $assignment) {
            [$scope, $name] = $this->variableReference($assignment, $statementScope);

            if ($name === null || ! in_array($scope, self::PERSISTENT_SCOPES, true)) {
                continue;
            }

            if (in_array($name, $dangerous, true)) {
                $violations[] = "SET {$scope} {$name} is not allowed through QueryProxy: {$name} controls server-side file paths, code loading or a protection switch.";
            }
        }

        return $violations;
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

            // "@@x" with no qualifying dot is a session variable.
            if (! isset($assignment[$offset]) || $assignment[$offset]->token !== '.') {
                return [null, null];
            }

            return [$scope, isset($assignment[$offset + 1]) ? $this->identifierValue($assignment[$offset + 1]) : null];
        }

        $leading = strtoupper((string) $first->value);

        if (in_array($leading, [...self::PERSISTENT_SCOPES, 'SESSION', 'LOCAL'], true)) {
            return [$leading, isset($assignment[1]) ? $this->identifierValue($assignment[1]) : null];
        }

        return [$statementScope, $this->identifierValue($first)];
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

        foreach ((new Lexer($this->maskDollarQuoted($sql)))->list->tokens as $token) {
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
     * reports "general_log:=1" as a label token named "general_log:".
     */
    private function identifierValue(Token $token): string
    {
        return strtolower(rtrim((string) $token->value, ':'));
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
     *    comment the two readings disagree on is rejected.
     *  - Every driver: a block comment that is never closed is rejected.
     *
     * A "#" comment is left to the lexer: it is a comment in MySQL only, and
     * its PostgreSQL reading is part of the driver-aware tokenization that is
     * still to come.
     *
     * @return array{0: string, 1: ?string} [sql to inspect, violation]
     */
    private function resolveComments(string $sql, ?DbDriver $driver): array
    {
        $unterminated = 'A block comment that is never closed is not allowed.';

        if ($driver !== null && $driver !== DbDriver::Pgsql) {
            return [$sql, $this->hasUnterminatedBlockComment($sql) ? $unterminated : null];
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
     * True when the lexer reports a block comment with no closing marker.
     * The opening marker of a MySQL executable comment is its own token and
     * is closed by a separate token, so it is not one.
     */
    private function hasUnterminatedBlockComment(string $sql): bool
    {
        foreach ((new Lexer($sql))->list->tokens as $token) {
            if ($token->type !== TokenType::Comment || ! str_starts_with($token->token, '/*')) {
                continue;
            }

            if (preg_match('/^\/\*(!|M!)\d*$/', $token->token)) {
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
     * @return array{comments: list<array{0: int, 1: int}>, quoted: list<array{0: int, 1: int}>}|null
     */
    private function postgresLexicalRanges(string $sql): ?array
    {
        $comments = [];
        $quoted = [];
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

                if ($depth > 0) {
                    return null;
                }

                $comments[] = [$offset, $cursor];
                $offset = $cursor;

                continue;
            }

            if ($char === "'") {
                $escapes = ($previous === 'E' || $previous === 'e')
                    && ! $this->isPostgresIdentifierCharacter($offset > 1 ? $sql[$offset - 2] : '');
                $offset = $this->skipPostgresQuoted($sql, $offset, $escapes);

                continue;
            }

            if ($char === '"') {
                $offset = $this->skipPostgresQuoted($sql, $offset, false);

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

        return ['comments' => $comments, 'quoted' => $quoted];
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

        foreach ((new Lexer($sql))->list->tokens as $token) {
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
        foreach ($ranges as [$start, $end]) {
            $blank = (string) preg_replace('/[^\r\n]/', ' ', substr($sql, $start, $end - $start));
            $sql = substr_replace($sql, $blank, $start, $end - $start);
        }

        return $sql;
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
     * True when a data-modifying keyword appears anywhere in the statement,
     * at any nesting depth — CTE bodies are always parenthesised, so a
     * depth-0-only scan would miss WITH x AS (INSERT ...) entirely.
     */
    private function hasWriteKeyword(string $sql): bool
    {
        $tokens = (new Lexer($sql))->list->tokens;

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
