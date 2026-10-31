<?php

namespace App\Console\Commands;

use App\Services\Connections\GrantService;
use Illuminate\Console\Command;

/**
 * Removes time-bound connection grants whose end time has passed and records
 * `grant.expired` for each (ADR-025). Access has already stopped at the end
 * time (Connection::isGrantedTo ignores an expired grant); this is cleanup
 * and the audit trail, so a late run delays only the record.
 */
class ExpireConnectionGrants extends Command
{
    protected $signature = 'connections:expire-grants';

    protected $description = 'Remove expired time-bound connection grants and record them in the audit log';

    public function handle(GrantService $grants): int
    {
        $removed = $grants->expireDue();

        $this->info("Removed {$removed} expired grant(s).");

        return self::SUCCESS;
    }
}
