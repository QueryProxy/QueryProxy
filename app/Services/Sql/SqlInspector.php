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
 * stripped, whitespace collapsed, string literals blanked) so that comment
 * tricks like "DROP/**\/DATABASE" cannot smuggle a forbidden statement past
 * the anchored patterns.
 *
 * The parser is MySQL-dialect-first; statements it cannot fully parse are
 * guarded best-effort by keyword heuristics and classified as writes unless
 * they clearly read.
 */
class SqlInspector
{
    private const READ_KEYWORDS = ['SELECT', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC'];

    private const DDL_KEYWORDS = ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'];

    private const FORBIDDEN_PATTERNS = [
        '/^DROP\s+(DATABASE|SCHEMA)\b/i' => 'DROP DATABASE is not allowed through QueryProxy.',
        '/^GRANT\b/i' => 'GRANT statements are not allowed through QueryProxy.',
        '/^REVOKE\b/i' => 'REVOKE statements are not allowed through QueryProxy.',
        '/^(CREATE|ALTER|DROP)\s+(USER|ROLE|LOGIN)\b/i' => 'User / role management statements are not allowed through QueryProxy.',
        '/^SET\s+GLOBAL\b/i' => 'SET GLOBAL is not allowed through QueryProxy.',
        '/^SHUTDOWN\b/i' => 'SHUTDOWN is not allowed through QueryProxy.',
        '/^LOAD\s+DATA\b/i' => 'LOAD DATA is not allowed through QueryProxy.',
        '/^COPY\b/i' => 'COPY is not allowed through QueryProxy (server-side file / program IO).',
        '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i' => 'SELECT ... INTO OUTFILE / DUMPFILE is not allowed through QueryProxy.',
        '/\bLOAD_FILE\s*\(/i' => 'LOAD_FILE() is not allowed through QueryProxy.',
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
     * @return list<string>
     */
    public function splitStatements(string $sql): array
    {
        $lexer = new Lexer($sql);

        $segments = [];
        $start = 0;

        foreach ($lexer->list->tokens as $token) {
            if ($token->type === TokenType::Delimiter && $token->token !== '' && $token->position !== null) {
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
     * contents can never satisfy (or dodge) a pattern.
     */
    private function normalize(string $sql): string
    {
        $tokens = (new Lexer($sql))->list->tokens;
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
