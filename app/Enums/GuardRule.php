<?php

namespace App\Enums;

/**
 * The family of guard rule that refused a statement, as the `rule` label of
 * queryproxy_guard_rejections_total (ADR-022).
 *
 * SqlInspector reports violations as free text, which would be unbounded as a
 * label (and carry identifiers), so each message is mapped onto this fixed
 * set; anything unrecognised counts as Other.
 */
enum GuardRule: string
{
    case WhereClause = 'where_clause';
    case PrivilegeManagement = 'privilege_management';
    case ServerControl = 'server_control';
    case FileIo = 'file_io';
    case DynamicSql = 'dynamic_sql';
    case TransactionControl = 'transaction_control';
    case SessionSetting = 'session_setting';
    case UnreadableSql = 'unreadable_sql';
    case Other = 'other';

    /** Patterns are tried in order; the first that matches names the rule. */
    private const PATTERNS = [
        // First: the SQL Server batch messages mention BEGIN / END / CASE, and
        // would otherwise land in transaction_control or other.
        'unreadable_sql' => '/\b(cannot tell where|does not know|CASE expression|No executable|could not read|Unicode|unreadable|comment|lexer)\b/i',
        'where_clause' => '/\bWHERE clause\b/i',
        'file_io' => '/\b(LOAD DATA|COPY|OUTFILE|DUMPFILE|LOAD_FILE|file IO|files on the database server)\b/i',
        'privilege_management' => '/\b(GRANT|REVOKE|DENY)\b|User \/ role management|\bROLE\b|SESSION AUTHORIZATION/',
        'server_control' => '/\b(SHUTDOWN|ALTER SYSTEM|DROP DATABASE|SONAME|controls the server)\b/i',
        'dynamic_sql' => '/\b(EXEC|EXECUTE|PREPARE|sp_executesql|DO block|dynamic SQL|CTE body|runs a statement|cannot read)\b/i',
        'transaction_control' => '/\b(transaction|ROLLBACK|COMMIT|BEGIN|Multiple statements|READ WRITE)\b/i',
        'session_setting' => '/\b(SET|RESET|PRAGMA|set_config|variable|setting)\b/i',
    ];

    public static function classify(string $violation): self
    {
        foreach (self::PATTERNS as $rule => $pattern) {
            if (preg_match($pattern, $violation) === 1) {
                return self::from($rule);
            }
        }

        return self::Other;
    }

    /**
     * The distinct rules behind a list of violations, in first-seen order.
     *
     * @param  list<string>  $violations
     * @return list<self>
     */
    public static function fromViolations(array $violations): array
    {
        $rules = [];

        foreach ($violations as $violation) {
            $rule = self::classify($violation);
            $rules[$rule->value] = $rule;
        }

        return array_values($rules);
    }
}
