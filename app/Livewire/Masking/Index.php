<?php

namespace App\Livewire\Masking;

use App\Enums\MaskMatchType;
use App\Enums\MaskStrategy;
use App\Models\Connection;
use App\Models\MaskingRule;
use App\Models\Team;
use App\Services\Masking\Masker;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Masking Rules')]
class Index extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $matchType = 'column';

    public string $pattern = '';

    public string $strategy = 'partial';

    public ?int $connectionId = null;

    // Playground
    public string $sampleColumn = 'email';

    public string $sampleValue = 'ada@example.com';

    public ?string $sampleResult = null;

    private function team(): Team
    {
        return auth()->user()->currentTeam() ?? abort(403);
    }

    /**
     * Route middleware only guards the initial page load; every action must
     * re-check because Livewire updates arrive on a separate endpoint and the
     * actor's role may have been changed since the page was served.
     */
    private function assertDba(Team $team): void
    {
        $user = auth()->user();

        abort_unless($user->isAdmin() || $user->isDbaIn($team), 403);
    }

    public function openCreate(): void
    {
        $this->reset('editingId', 'name', 'pattern', 'connectionId');
        $this->matchType = 'column';
        $this->strategy = 'partial';
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function openEdit(int $ruleId): void
    {
        $rule = MaskingRule::forTeam($this->team())->findOrFail($ruleId);

        $this->editingId = $rule->id;
        $this->name = $rule->name;
        $this->matchType = $rule->match_type->value;
        $this->pattern = $rule->pattern;
        $this->strategy = $rule->strategy->value;
        $this->connectionId = $rule->connection_id;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(): void
    {
        $team = $this->team();
        $this->assertDba($team);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'matchType' => ['required', Rule::enum(MaskMatchType::class)],
            'pattern' => ['required', 'string', 'max:255'],
            'strategy' => ['required', Rule::enum(MaskStrategy::class)],
            'connectionId' => ['nullable', Rule::exists('connections', 'id')->where('team_id', $team->id)],
        ]);

        if ($validated['matchType'] === 'regex') {
            if (@preg_match($validated['pattern'], '') === false) {
                $this->addError('pattern', 'This is not a valid PCRE regular expression (delimiters required, e.g. /.../).');

                return;
            }

            // Stress the pattern against a long probe string: catastrophic
            // backtracking trips PCRE's backtrack limit and returns false,
            // so a rule that would burn worker CPU is rejected up-front.
            if (@preg_match($validated['pattern'], str_repeat('aA0.! ', 2000)) === false) {
                $this->addError('pattern', 'This pattern is too complex to evaluate safely (catastrophic backtracking).');

                return;
            }
        }

        $attributes = [
            'name' => $validated['name'],
            'match_type' => $validated['matchType'],
            'pattern' => $validated['pattern'],
            'strategy' => $validated['strategy'],
            'connection_id' => $validated['connectionId'],
        ];

        if ($this->editingId) {
            $rule = MaskingRule::forTeam($team)->findOrFail($this->editingId);
            $rule->update($attributes);
            audit()->record('masking_rule.updated', team: $team, metadata: ['rule' => $rule->name]);
        } else {
            $rule = MaskingRule::create($attributes + ['team_id' => $team->id]);
            audit()->record('masking_rule.created', team: $team, metadata: ['rule' => $rule->name]);
        }

        $this->showForm = false;
    }

    public function toggle(int $ruleId): void
    {
        $this->assertDba($this->team());

        $rule = MaskingRule::forTeam($this->team())->findOrFail($ruleId);
        $rule->update(['enabled' => ! $rule->enabled]);

        audit()->record('masking_rule.toggled', team: $rule->team, metadata: [
            'rule' => $rule->name, 'enabled' => $rule->enabled,
        ]);
    }

    public function deleteRule(int $ruleId): void
    {
        $this->assertDba($this->team());

        $rule = MaskingRule::forTeam($this->team())->findOrFail($ruleId);

        audit()->record('masking_rule.deleted', team: $rule->team, metadata: ['rule' => $rule->name]);

        $rule->delete();
    }

    public function addDefaults(): void
    {
        $team = $this->team();
        $this->assertDba($team);

        foreach (MaskingRule::defaults() as $default) {
            MaskingRule::firstOrCreate(
                ['team_id' => $team->id, 'pattern' => $default['pattern'], 'match_type' => $default['match_type']],
                $default,
            );
        }

        audit()->record('masking_rule.defaults_added', team: $team);
    }

    public function preview(Masker $masker): void
    {
        $rules = MaskingRule::forTeam($this->team())->where('enabled', true)->get();

        $result = $masker->maskValue($this->sampleColumn, $this->sampleValue, $rules);

        $this->sampleResult = (string) $result;
    }

    public function render()
    {
        $team = $this->team();

        return view('livewire.masking.index', [
            'rules' => MaskingRule::forTeam($team)->with('connection')->orderBy('name')->get(),
            'connections' => Connection::forTeam($team)->orderBy('name')->get(),
            'matchTypes' => MaskMatchType::cases(),
            'strategies' => MaskStrategy::cases(),
        ]);
    }
}
