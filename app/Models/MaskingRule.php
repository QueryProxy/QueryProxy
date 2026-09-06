<?php

namespace App\Models;

use App\Enums\MaskMatchType;
use App\Enums\MaskStrategy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'team_id', 'connection_id', 'name', 'match_type', 'pattern', 'strategy', 'enabled',
])]
class MaskingRule extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'match_type' => MaskMatchType::class,
            'strategy' => MaskStrategy::class,
            'enabled' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function scopeForTeam(Builder $query, Team $team): Builder
    {
        return $query->where('team_id', $team->id);
    }

    /**
     * Sensible defaults every team starts from.
     *
     * @return list<array{name: string, match_type: string, pattern: string, strategy: string}>
     */
    public static function defaults(): array
    {
        return [
            ['name' => 'Email addresses (column)', 'match_type' => 'column', 'pattern' => '*email*', 'strategy' => 'partial'],
            ['name' => 'Phone numbers (column)', 'match_type' => 'column', 'pattern' => '*phone*', 'strategy' => 'partial'],
            ['name' => 'Credit cards (column)', 'match_type' => 'column', 'pattern' => '*card*', 'strategy' => 'partial'],
            ['name' => 'Secrets & tokens (column)', 'match_type' => 'column', 'pattern' => '*password*|*secret*|*token*|*api_key*', 'strategy' => 'full'],
            ['name' => 'Email addresses (content)', 'match_type' => 'regex', 'pattern' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', 'strategy' => 'partial'],
            ['name' => 'Credit card numbers (content)', 'match_type' => 'regex', 'pattern' => '/\b(?:\d[ -]?){13,16}\b/', 'strategy' => 'partial'],
        ];
    }
}
