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
 * reaches the result store (see MVP_PLAN §Phase 6).
 */
class Masker
{
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

        if (is_string($value)) {
            foreach ($rules as $rule) {
                if ($rule->match_type === MaskMatchType::Regex && @preg_match($rule->pattern, $value) === 1) {
                    return $this->apply($rule->strategy, $value);
                }
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
