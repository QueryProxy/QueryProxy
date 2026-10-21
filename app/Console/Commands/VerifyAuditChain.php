<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditVerifier;
use Illuminate\Console\Command;

class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verify the audit log hash chain from the first record';

    public function handle(AuditVerifier $verifier): int
    {
        $result = $verifier->verify();

        if (! $result['ok']) {
            $where = $result['broken_id'] === null ? 'at the end of the chain' : "at record id {$result['broken_id']}";
            $this->error("Audit chain broken {$where}: {$result['reason']}.");
            $this->line("Records verified before the break: {$result['checked']}.");

            return self::FAILURE;
        }

        $this->info("Audit chain intact: {$result['checked']} records verified.");
        $this->line("Chain head: {$result['head']}");

        return self::SUCCESS;
    }
}
