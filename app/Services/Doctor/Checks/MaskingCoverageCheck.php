<?php

namespace App\Services\Doctor\Checks;

use App\Models\Team;
use App\Services\Doctor\CheckResult;
use App\Services\Doctor\NeedsDatabase;

/** A team with no enabled masking rule sees every column unmasked. */
class MaskingCoverageCheck extends BaseCheck implements NeedsDatabase
{
    public function name(): string
    {
        return 'masking_coverage';
    }

    public function run(): CheckResult
    {
        if (! Team::query()->exists()) {
            return $this->skip('No teams yet.');
        }

        $bare = Team::query()
            ->whereDoesntHave('maskingRules', fn ($query) => $query->where('enabled', true))
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return $bare === []
            ? $this->ok('Every team has at least one enabled masking rule.')
            : $this->warn(count($bare).' team(s) have no enabled masking rule, so their query results are not masked ('.$this->nameList($bare).'). Install the default rules on the masking page.');
    }
}
