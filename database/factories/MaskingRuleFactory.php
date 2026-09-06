<?php

namespace Database\Factories;

use App\Models\MaskingRule;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaskingRule>
 */
class MaskingRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'connection_id' => null,
            'name' => 'Emails',
            'match_type' => 'column',
            'pattern' => '*email*',
            'strategy' => 'partial',
            'enabled' => true,
        ];
    }
}
