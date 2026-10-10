<?php

namespace App\Services\Masking;

use App\Enums\MaskMatchType;
use App\Enums\MaskStrategy;
use App\Models\Connection;
use App\Models\MaskingRule;
use Illuminate\Support\Collection;

/**
 * Applies dynamic data masking rules to result rows.
 *
 * Rules are resolved per target connection (team-wide rules plus rules pinned
 * to that connection). Column-pattern rules win over content-regex rules for
 * the same value; results are masked at write time so unmasked data never
 * reaches the result store.
 */
class Masker
{
    /**
     * Literals in an error message that may hold row values; leftmost match
     * wins. `dup`: MySQL `Duplicate entry '<v>' for key`. `key`: PostgreSQL
     * `Key (cols)=(<v>) already exists` (`keyLoose` for an unfamiliar suffix).
     * `row`: PostgreSQL `Failing row contains (<v>)`. `backtick`: a name, kept.
     * `single` / `double`: any quoted run; an unterminated run swallows the
     * rest of the text. Possessive quantifiers keep long messages clear of
     * the PCRE backtrack limit.
     */
    private const TEXT_LITERAL_PATTERN = <<<'REGEX'
        ~
          (?<dupHead>Duplicate\ entry\ ')(?<dupValue>.*?)'\ for\ key\b
        | (?<keyHead>\bKey\ \([^)]*\)=\()(?<keyValue>.*?)\)(?=\s+(?:already\ exists|is\ not\ present|is\ still\ referenced)\b|\W*\z)
        | (?<keyLooseHead>\bKey\ \([^)]*\)=\()(?<keyLooseValue>[^)]*)\)?
        | (?<rowHead>Failing\ row\ contains\ \()(?<rowValue>.*?)(?:\)(?=\.?\s*(?:\(Connection:|\z))|\z)
        | `[^`]*+`
        | '(?<single>(?:[^'\\]++|\\.|'')*+)(?:'|\z)
        | "(?<double>(?:[^"\\]++|\\.|"")*+)(?:"|\z)
        ~xsiu
        REGEX;

    /**
     * @return Collection<int, MaskingRule>
     */
    public function rulesFor(Connection $connection): Collection
    {
        return MaskingRule::query()
            ->where('team_id', $connection->team_id)
            ->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('connection_id')->orWhere('connection_id', $connection->id))
            ->get();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, MaskingRule>  $rules
     * @return array<string, mixed>
     */
    public function maskRow(array $row, Collection $rules): array
    {
        if ($rules->isEmpty()) {
            return $row;
        }

        foreach ($row as $column => $value) {
            $row[$column] = $this->maskValue($column, $value, $rules);
        }

        return $row;
    }

    /**
     * @param  Collection<int, MaskingRule>  $rules
     */
    public function maskValue(string $column, mixed $value, Collection $rules): mixed
    {
        if ($value === null) {
            return null;
        }

        foreach ($rules as $rule) {
            if ($rule->match_type === MaskMatchType::Column && $this->columnMatches($rule->pattern, $column)) {
                return $this->apply($rule->strategy, (string) $value);
            }
        }

        // Content rules must see every scalar, not just strings. PDO returns
        // native int/float for BIGINT/DECIMAL columns (Laravel disables
        // ATTR_STRINGIFY_FETCHES), so a `payments.pan BIGINT` that matches no
        // column pattern used to skip the content rules entirely and land raw
        // in the result store. Cast for matching only — an unmatched value is
        // returned untouched below so its original type survives.
        if (is_scalar($value)) {
            $subject = (string) $value;

            foreach ($rules as $rule) {
                if ($rule->match_type !== MaskMatchType::Regex) {
                    continue;
                }

                $matched = @preg_match($rule->pattern, $subject);

                // Fail closed: when PCRE cannot evaluate the pattern against
                // this value (backtrack/recursion limit), treat it as a match —
                // emitting the raw value on evaluation failure would silently
                // bypass masking exactly when the data is most pathological.
                if ($matched === 1 || $matched === false) {
                    return $this->apply($rule->strategy, $subject);
                }
            }
        }

        return $value;
    }

    /**
     * Mask free text such as a driver error message, with the same rules that
     * mask result rows.
     *
     * Column rules cannot be matched against a column name here — the text only
     * *mentions* columns — so they are applied fail-closed: when any identifier
     * in the text (a bare word, a quoted or backticked name, a `Key (col)=(v)`
     * column list, `table.column` and its last part) matches a column rule,
     * every literal value in the message is masked with the strongest strategy
     * among the matching rules (`full` > `hash` > `partial`). The identifier
     * itself is left readable. A single stray apostrophe (MySQL's `doesn't
     * exist`) would shift the quote pairing and leave a value outside the
     * quotes, so when a column rule matches and the quotes do not balance the
     * whole message is masked.
     *
     * Every literal value is also run through the regex rules as a whole, the
     * way maskValue() sees a result value, so anchored rules (`\A...\z`,
     * `^...$`) fire on it; then each regex rule is applied in place to every
     * match in the text.
     *
     * PostgreSQL's `Failing row contains (...)` echoes a whole row without
     * naming its columns, so it is masked whenever any rule exists, with the
     * strongest strategy among all rules.
     *
     * Fail-closed: if PCRE cannot evaluate the text or a pattern, the whole
     * message becomes `*****`, as maskValue() does for a value.
     *
     * @param  Collection<int, MaskingRule>  $rules
     */
    public function maskText(string $text, Collection $rules): string
    {
        if ($text === '' || $rules->isEmpty()) {
            return $text;
        }

        // Invalid UTF-8 would fail the /u scans below; replace the bad bytes
        // rather than discarding the whole message.
        $text = mb_scrub($text);

        $columnStrategy = $this->columnStrategyFor($text, $rules);

        if ($columnStrategy === false) {
            return '*****';
        }

        if ($columnStrategy !== null && $this->hasUnbalancedQuotes($text)) {
            return '*****';
        }

        $rowStrategy = $this->strongestStrategy($rules->map(fn (MaskingRule $rule) => $rule->strategy)->all());

        $masked = @preg_replace_callback(
            self::TEXT_LITERAL_PATTERN,
            function (array $match) use ($text, $rules, $columnStrategy, $rowStrategy): string {
                $group = fn (string $name): ?string => $match[$name][0] ?? null;

                foreach (['dup' => $columnStrategy, 'key' => $columnStrategy, 'keyLoose' => $columnStrategy, 'row' => $rowStrategy] as $name => $strategy) {
                    $value = $group($name.'Value');

                    if ($value !== null) {
                        $head = $group($name.'Head');

                        return $head.$this->maskLiteral($value, $strategy, $rules).substr($match[0][0], strlen($head) + strlen($value));
                    }
                }

                $quoted = $group('single') ?? $group('double');

                if ($quoted === null) {
                    return $match[0][0];
                }

                $before = substr($text, max(0, $match[0][1] - 24), min(24, $match[0][1]));

                if ($this->isObjectName($quoted, $before)) {
                    return $match[0][0];
                }

                return $match[0][0][0].$this->maskLiteral($quoted, $columnStrategy, $rules).substr($match[0][0], 1 + strlen($quoted));
            },
            $text,
            flags: PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
        );

        if ($masked === null) {
            return '*****';
        }

        foreach ($rules as $rule) {
            if ($rule->match_type !== MaskMatchType::Regex) {
                continue;
            }

            $masked = @preg_replace_callback(
                $rule->pattern,
                fn (array $match): string => $match[0] === '' ? '' : $this->apply($rule->strategy, $match[0]),
                $masked,
            );

            if ($masked === null) {
                return '*****';
            }
        }

        return $masked;
    }

    /**
     * Strongest strategy among the column rules that match an identifier in
     * the text; null when none matches, false when PCRE fails.
     *
     * @param  Collection<int, MaskingRule>  $rules
     */
    private function columnStrategyFor(string $text, Collection $rules): MaskStrategy|false|null
    {
        $columnRules = $rules->filter(fn (MaskingRule $rule) => $rule->match_type === MaskMatchType::Column);

        if ($columnRules->isEmpty()) {
            return null;
        }

        if (@preg_match_all('/[\p{L}\p{N}_$]+(?:\.[\p{L}\p{N}_$]+)*/u', $text, $found) === false) {
            return false;
        }

        $matched = [];

        foreach (array_unique($found[0]) as $name) {
            $candidates = str_contains($name, '.') ? [$name, ...explode('.', $name)] : [$name];

            foreach ($columnRules as $rule) {
                foreach ($candidates as $candidate) {
                    if ($this->columnMatches($rule->pattern, $candidate)) {
                        $matched[] = $rule->strategy;
                    }
                }
            }
        }

        return $this->strongestStrategy($matched);
    }

    /**
     * @param  list<MaskStrategy>  $strategies
     */
    private function strongestStrategy(array $strategies): ?MaskStrategy
    {
        $strongest = null;
        $strongestWeight = 0;

        foreach ($strategies as $strategy) {
            $weight = match ($strategy) {
                MaskStrategy::Full => 3,
                MaskStrategy::Hash => 2,
                MaskStrategy::Partial => 1,
            };

            if ($weight > $strongestWeight) {
                $strongest = $strategy;
                $strongestWeight = $weight;
            }
        }

        return $strongest;
    }

    /**
     * Whether a quoted run is an object name rather than a data value: it must
     * be identifier-shaped and follow a keyword that introduces an object name
     * (`column "x"`, `for key 'users.email'`), or be one of MySQL's clause
     * names (`in 'field list'`). Anything else is treated as a value.
     */
    private function isObjectName(string $quoted, string $before): bool
    {
        if (preg_match('/\bin\s+\z/i', $before) === 1) {
            return preg_match('/\A(?:field list|where clause|order clause|group statement|having clause|on clause|from clause)\z/i', $quoted) === 1;
        }

        return preg_match('/\A[\p{L}\p{N}_$]+(?:\.[\p{L}\p{N}_$]+)*\z/u', $quoted) === 1
            && preg_match('/\b(?:column|columns|relation|table|key|constraint|index|schema|database|field|sequence|view|function|trigger|collation|type|enum|domain|from|into|update|join)\s+\z/i', $before) === 1;
    }

    /**
     * Whether a quote character occurs an odd number of times (escaped quotes
     * ignored). Also true when PCRE fails, so the caller fails closed.
     */
    private function hasUnbalancedQuotes(string $text): bool
    {
        $unescaped = @preg_replace('/\\\\./su', '', $text);

        if ($unescaped === null) {
            return true;
        }

        return substr_count($unescaped, "'") % 2 === 1 || substr_count($unescaped, '"') % 2 === 1;
    }

    /**
     * Mask a literal. A column strategy (from a matching identifier) wins;
     * without one the regex rules see the whole value, as in maskValue(), and
     * a literal that matches none stays. A value that already is a mask
     * (`*****`, `sha256:...`) is kept so that masking is idempotent — the
     * backfill can run twice without rewriting its own output.
     *
     * @param  Collection<int, MaskingRule>  $rules
     */
    private function maskLiteral(string $value, ?MaskStrategy $strategy, Collection $rules): string
    {
        if ($value === '' || preg_match('/\A(?:\*{5}|sha256:[0-9a-f]{12})\z/', $value) === 1) {
            return $value;
        }

        if ($strategy !== null) {
            return $this->apply($strategy, $value);
        }

        foreach ($rules as $rule) {
            if ($rule->match_type !== MaskMatchType::Regex) {
                continue;
            }

            $matched = @preg_match($rule->pattern, $value);

            // Fail closed on a PCRE failure, exactly like maskValue().
            if ($matched === 1 || $matched === false) {
                return $this->apply($rule->strategy, $value);
            }
        }

        return $value;
    }

    /** Wildcard match, case-insensitive; `|` separates alternative patterns. */
    public function columnMatches(string $pattern, string $column): bool
    {
        foreach (explode('|', $pattern) as $alternative) {
            if (fnmatch(strtolower(trim($alternative)), strtolower($column))) {
                return true;
            }
        }

        return false;
    }

    public function apply(MaskStrategy $strategy, string $value): string
    {
        return match ($strategy) {
            MaskStrategy::Full => '*****',
            MaskStrategy::Hash => 'sha256:'.substr(hash('sha256', $value), 0, 12),
            MaskStrategy::Partial => $this->partial($value),
        };
    }

    private function partial(string $value): string
    {
        $length = mb_strlen($value);

        if ($length <= 3) {
            return '***';
        }

        // Keep the shape of emails readable: first char of the local part + domain TLD.
        if (str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);
            $tld = str_contains($domain, '.') ? substr($domain, (int) strrpos($domain, '.')) : '';

            return mb_substr($local, 0, 1).'***@***'.$tld;
        }

        return mb_substr($value, 0, 1).str_repeat('*', min($length - 2, 8)).mb_substr($value, -1);
    }
}
