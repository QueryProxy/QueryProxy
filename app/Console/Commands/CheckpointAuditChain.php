<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use Illuminate\Console\Command;

class CheckpointAuditChain extends Command
{
    protected $signature = 'audit:checkpoint';

    protected $description = 'Record the latest audit chain hash as an audit event';

    public function handle(): int
    {
        $last = AuditLog::query()->latest('id')->first();

        if ($last === null || $last->action === AuditEvent::AuditCheckpoint->value) {
            $this->line('Nothing to checkpoint: no audit record was written since the last checkpoint.');

            return self::SUCCESS;
        }

        audit()->record(AuditEvent::AuditCheckpoint, metadata: [
            'last_id' => $last->id,
            'last_hash' => $last->hash,
            'records' => AuditLog::query()->count(),
        ]);

        $this->info("Checkpoint recorded at record id {$last->id}.");

        return self::SUCCESS;
    }
}
