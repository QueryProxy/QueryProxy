<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Models\Team;
use Illuminate\Console\Command;

/**
 * Replaces a team's `hmac` masking key. Pseudonyms made with the old key no
 * longer equal pseudonyms made with the new one, so anyone who joined or
 * compared masked results across the rotation loses that match. The key
 * itself is never printed, logged or put in the audit entry.
 */
class RotateMaskingKey extends Command
{
    protected $signature = 'queryproxy:masking:rotate-key {team : Team id or slug} {--force : Rotate without asking for confirmation}';

    protected $description = 'Generate a new HMAC masking key for a team (old and new pseudonyms stop matching)';

    public function handle(): int
    {
        $identifier = (string) $this->argument('team');

        // An exact id wins over a slug, so a team whose slug is "7" can never
        // be rotated by mistake when team id 7 is meant.
        $team = ctype_digit($identifier) ? Team::query()->find((int) $identifier) : null;
        $team ??= Team::query()->where('slug', $identifier)->first();

        if ($team === null) {
            $this->error("Team not found: {$identifier}");

            return self::FAILURE;
        }

        $this->warn('After rotation, hmac: values made with the old key will no longer match values made with the new one; comparisons and joins on masked columns break.');

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Confirmation is required; pass --force when running non-interactively.');

                return self::FAILURE;
            }

            if (! $this->confirm("Rotate the masking key of team \"{$team->name}\"?", false)) {
                $this->line('Cancelled; the key was not changed.');

                return self::FAILURE;
            }
        }

        $team->rotateMaskingKey();

        audit()->record(AuditEvent::MaskingKeyRotated, team: $team, metadata: ['team_slug' => $team->slug]);

        $this->info("Masking key of team \"{$team->name}\" rotated. Old hmac: values will not match new ones.");

        return self::SUCCESS;
    }
}
