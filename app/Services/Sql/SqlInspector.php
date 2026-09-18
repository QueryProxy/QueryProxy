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
 * The parser is MySQL-dialect-first; statements it cannot fully parse are
 * guarded best-effort by keyword heuristics and classified as writes unless
 * they clearly read.
 */
class SqlInspector
{
    private const READ_KEYWORDS = ['SELECT', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC'];

    private const DDL_KEYWORDS = ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'];

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
    private function analyzeStatement(string $sql, int $position, ?DbDriver $driver): array
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

        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;
        $parsed = $statement !== null && $parser->errors === [];

        $keyword = strtoupper(explode(' ', $normalized, 2)[0]);

        $type = $this->classify($statement, $keyword, $sql, $parsed);
        $isDdl = in_array($keyword, self::DDL_KEYWORDS, true);

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

        if ($type === StatementType::Read && in_array($keyword, ['SELECT', 'WITH'], true)) {
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

        return $body === null
            ? $violations
            : array_merge($violations, $this->forbiddenInBlockBody($body));
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

            if ($char === '$'
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
