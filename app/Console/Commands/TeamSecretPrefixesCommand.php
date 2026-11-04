<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Secrets\TeamSecretPrefixes;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Shows or replaces the external secret reference prefixes of a team
 * (ADR-028 amendment). A team without prefixes cannot use references.
 */
class TeamSecretPrefixesCommand extends Command
{
    protected $signature = 'queryproxy:team:secret-prefixes
        {team : Team id or slug}
        {prefixes?* : The complete new list, for example vault://secret/teams/payments (omit to only show the current list)}
        {--clear : Remove every prefix, so the team can no longer use references}';

    protected $description = 'Show or set the external secret reference prefixes a team may use';

    public function handle(TeamSecretPrefixes $service): int
    {
        $identifier = (string) $this->argument('team');

        $team = ctype_digit($identifier) ? Team::query()->find((int) $identifier) : null;
        $team ??= Team::query()->where('slug', $identifier)->first();

        if ($team === null) {
            $this->error("Team not found: {$identifier}");

            return self::FAILURE;
        }

        $prefixes = (array) $this->argument('prefixes');

        if ($prefixes === [] && ! $this->option('clear')) {
            $this->show($team);

            return self::SUCCESS;
        }

        if ($prefixes !== [] && $this->option('clear')) {
            $this->error('Give either prefixes or --clear, not both.');

            return self::FAILURE;
        }

        try {
            $service->set($team, $prefixes);
        } catch (ValidationException $e) {
            $this->error($e->validator->errors()->first());

            return self::FAILURE;
        }

        $this->show($team->refresh());

        return self::SUCCESS;
    }

    private function show(Team $team): void
    {
        $prefixes = $team->secretReferencePrefixes();

        if ($prefixes === []) {
            $this->line("Team \"{$team->name}\" has no secret reference prefixes: it cannot use external secret references.");

            return;
        }

        $this->line("Team \"{$team->name}\" may use references under:");

        foreach ($prefixes as $prefix) {
            $this->line("  {$prefix}");
        }
    }
}
