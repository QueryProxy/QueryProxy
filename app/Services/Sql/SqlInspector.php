<?php

namespace App\Services\Sql;

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
 *    hard cap are clamped.
 *  - Multiple statements are only allowed inside an explicit
 *    BEGIN; ...; COMMIT; transaction block.
 *  - A small set of administrative statements is always rejected.
 *
 * The parser is MySQL-dialect-first; statements it cannot fully parse are
 * guarded best-effort by keyword heuristics and classified as writes unless
 * they clearly read.
 */
class SqlInspector
{
    private const READ_KEYWORDS = ['SELECT', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC'];

    private const FORBIDDEN_PATTERNS = [
        '/^DROP\s+(DATABASE|SCHEMA)\b/i' => 'DROP DATABASE is not allowed through QueryProxy.',
        '/^GRANT\b/i' => 'GRANT statements are not allowed through QueryProxy.',
        '/^REVOKE\b/i' => 'REVOKE statements are not allowed through QueryProxy.',
        '/^(CREATE|ALTER|DROP)\s+(USER|ROLE|LOGIN)\b/i' => 'User / role management statements are not allowed through QueryProxy.',
        '/^SET\s+GLOBAL\b/i' => 'SET GLOBAL is not allowed through QueryProxy.',
        '/^SHUTDOWN\b/i' => 'SHUTDOWN is not allowed through QueryProxy.',
    ];

    public function inspect(string $sql): InspectionResult
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
            [$info, $statementViolations] = $this->analyzeStatement($statementSql, $index + 1);
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
    private function analyzeStatement(string $sql, int $position): array
    {
        $violations = [];
        $label = "Statement {$position}";

        foreach (self::FORBIDDEN_PATTERNS as $pattern => $message) {
            if (preg_match($pattern, $sql)) {
                $violations[] = "{$label}: {$message}";
            }
        }

        $parser = new Parser($sql);
        $statement = $parser->statements[0] ?? null;
        $parsed = $statement !== null && $parser->errors === [];

        $keyword = strtoupper((string) preg_replace('/^\s*(\w+).*/s', '$1', $sql));

        $type = $this->classify($statement, $keyword, $sql, $parsed);

        // WHERE guard for UPDATE / DELETE.
        if ($statement instanceof UpdateStatement || $statement instanceof DeleteStatement) {
            if ($statement->where === null || $statement->where === []) {
                $violations[] = "{$label}: ".strtoupper($keyword).' without a WHERE clause is not allowed.';
            }
        } elseif (! $parsed && in_array($keyword, ['UPDATE', 'DELETE'], true)) {
            if (! preg_match('/\bWHERE\b/i', $sql)) {
                $violations[] = "{$label}: {$keyword} without a WHERE clause is not allowed.";
            }
        }

        // LIMIT guard for plain SELECTs (incl. read-only WITH ... SELECT).
        $preparedSql = $sql;
        $limitInjected = false;
        $limitClamped = false;

        if ($type === StatementType::Read && in_array($keyword, ['SELECT', 'WITH'], true)) {
            [$preparedSql, $limitInjected, $limitClamped] = $this->applyLimitGuard($sql);
        }

        return [
            new StatementInfo($sql, $preparedSql, $type, $parsed, $limitInjected, $limitClamped),
            $violations,
        ];
    }

    private function classify(?object $statement, string $keyword, string $sql, bool $parsed): StatementType
    {
        if ($statement instanceof SelectStatement
            || $statement instanceof ShowStatement
            || $statement instanceof ExplainStatement) {
            return StatementType::Read;
        }

        if ($keyword === 'WITH') {
            // A CTE wrapping data modification (PostgreSQL) is a write.
            return $this->hasTopLevelWriteKeyword($sql)
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
     * @return array{0: string, 1: bool, 2: bool} [preparedSql, injected, clamped]
     */
    private function applyLimitGuard(string $sql): array
    {
        $defaultLimit = (int) config('queryproxy.select_default_limit', 1000);
        $hardLimit = (int) config('queryproxy.select_hard_limit', 10000);

        $tokens = (new Lexer($sql))->list->tokens;

        $depth = 0;
        $limitIndex = null;
        $forIndex = null;

        foreach ($tokens as $i => $token) {
            if ($token->type === TokenType::Operator) {
                if ($token->token === '(') {
                    $depth++;
                } elseif ($token->token === ')') {
                    $depth--;
                }

                continue;
            }

            if ($depth !== 0 || $token->type !== TokenType::Keyword) {
                continue;
            }

            $upper = strtoupper($token->token);

            if ($upper === 'LIMIT') {
                $limitIndex = $i;
            } elseif ($forIndex === null && str_starts_with($upper, 'FOR ')) {
                // Locking clauses lex as compound keywords: "FOR UPDATE", "FOR SHARE", ...
                $forIndex = $i;
            }
        }

        if ($limitIndex === null) {
            // No top-level LIMIT: inject the default, before a FOR UPDATE/SHARE
            // locking clause when one exists.
            if ($forIndex !== null && $tokens[$forIndex]->position !== null) {
                $pos = $tokens[$forIndex]->position;

                return [rtrim(substr($sql, 0, $pos))." LIMIT {$defaultLimit} ".substr($sql, $pos), true, false];
            }

            return [rtrim(rtrim($sql), ';')." LIMIT {$defaultLimit}", true, false];
        }

        // Locate the row-count token of the LIMIT clause:
        //   LIMIT n | LIMIT offset, n | LIMIT n OFFSET o | LIMIT ALL
        $meaningful = [];

        for ($i = $limitIndex + 1, $count = count($tokens); $i < $count && count($meaningful) < 3; $i++) {
            $token = $tokens[$i];

            if (in_array($token->type, [TokenType::Whitespace, TokenType::Comment], true)) {
                continue;
            }

            $meaningful[] = $token;
        }

        $rowCountToken = null;

        if (isset($meaningful[0]) && $meaningful[0]->type === TokenType::Number) {
            $rowCountToken = $meaningful[0];

            if (isset($meaningful[1], $meaningful[2])
                && $meaningful[1]->type === TokenType::Operator
                && $meaningful[1]->token === ','
                && $meaningful[2]->type === TokenType::Number) {
                // MySQL "LIMIT offset, count" form: the second number is the count.
                $rowCountToken = $meaningful[2];
            }
        } elseif (isset($meaningful[0]) && strtoupper($meaningful[0]->token) === 'ALL') {
            // PostgreSQL "LIMIT ALL" means unlimited: clamp to the hard cap.
            $rowCountToken = $meaningful[0];
        }

        if ($rowCountToken === null || $rowCountToken->position === null) {
            return [$sql, false, false];
        }

        $value = strtoupper($rowCountToken->token) === 'ALL' ? PHP_INT_MAX : (int) $rowCountToken->token;

        if ($value <= $hardLimit) {
            return [$sql, false, false];
        }

        $prepared = substr($sql, 0, $rowCountToken->position)
            .$hardLimit
            .substr($sql, $rowCountToken->position + strlen($rowCountToken->token));

        return [$prepared, false, true];
    }

    private function hasTopLevelWriteKeyword(string $sql): bool
    {
        $tokens = (new Lexer($sql))->list->tokens;
        $depth = 0;

        foreach ($tokens as $token) {
            if ($token->type === TokenType::Operator) {
                if ($token->token === '(') {
                    $depth++;
                } elseif ($token->token === ')') {
                    $depth--;
                }

                continue;
            }

            if ($depth === 0
                && $token->type === TokenType::Keyword
                && in_array(strtoupper($token->token), ['INSERT', 'UPDATE', 'DELETE', 'MERGE'], true)) {
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
