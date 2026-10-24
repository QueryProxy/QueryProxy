<?php

namespace App\Services\Doctor;

use App\Services\Audit\AuditChain;
use App\Services\Audit\AuditVerifier;
use App\Services\Doctor\Checks\AppEnvironmentCheck;
use App\Services\Doctor\Checks\AppKeyCheck;
use App\Services\Doctor\Checks\AppKeyLocationCheck;
use App\Services\Doctor\Checks\AuditChainCheck;
use App\Services\Doctor\Checks\DatabaseCheck;
use App\Services\Doctor\Checks\FailedJobsCheck;
use App\Services\Doctor\Checks\MaskingCoverageCheck;
use App\Services\Doctor\Checks\MetricsCheck;
use App\Services\Doctor\Checks\QueueTimingCheck;
use App\Services\Doctor\Checks\ResultDiskCheck;
use App\Services\Doctor\Checks\SchedulerCheck;
use App\Services\Doctor\Checks\SecretFilesCheck;
use App\Services\Doctor\Checks\SiemCheck;
use App\Services\Doctor\Checks\SqliteAllowedDirCheck;
use App\Services\Doctor\Checks\StoragePermissionsCheck;
use App\Services\Doctor\Checks\TlsEnforcementCheck;
use App\Services\Doctor\Checks\TlsFilesCheck;
use App\Services\Doctor\Checks\TrustedProxiesCheck;
use App\Services\Doctor\Checks\WorkerCheck;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs the checks of queryproxy:doctor. A check that throws does not stop the
 * others: it is reported as a failure of its own.
 */
class Doctor
{
    /** @param  list<Check>  $checks */
    public function __construct(private readonly array $checks) {}

    /** The checks of a normal installation, in report order. */
    public static function default(bool $fullAuditVerification = false): self
    {
        return new self([
            new AppKeyCheck,
            new AppKeyLocationCheck,
            new SecretFilesCheck,
            new AppEnvironmentCheck,
            new DatabaseCheck,
            new QueueTimingCheck,
            new WorkerCheck,
            new SchedulerCheck,
            new FailedJobsCheck,
            new StoragePermissionsCheck,
            new ResultDiskCheck,
            new SqliteAllowedDirCheck,
            new TlsEnforcementCheck,
            new TlsFilesCheck,
            new MaskingCoverageCheck,
            new TrustedProxiesCheck,
            new AuditChainCheck(app(AuditChain::class), app(AuditVerifier::class), $fullAuditVerification),
            new SiemCheck,
            new MetricsCheck,
        ]);
    }

    /** @return list<CheckResult> */
    public function run(): array
    {
        $results = [];
        $databaseProblem = $this->databaseProblem();

        foreach ($this->checks as $check) {
            if ($databaseProblem !== null && $check instanceof NeedsDatabase) {
                $results[] = new CheckResult($check->name(), CheckStatus::Skip, 'Skipped: the application database is not reachable.');

                continue;
            }

            try {
                $results[] = $check->run();
            } catch (Throwable $e) {
                $results[] = new CheckResult(
                    $check->name(),
                    CheckStatus::Fail,
                    'The check could not run: '.$this->brief($e),
                );
            }
        }

        return $results;
    }

    private function databaseProblem(): ?string
    {
        try {
            DB::connection()->getPdo();

            return null;
        } catch (Throwable $e) {
            return $this->brief($e);
        }
    }

    private function brief(Throwable $e): string
    {
        $line = trim(strtok($e->getMessage(), "\n") ?: $e::class);

        return mb_strlen($line) > 160 ? mb_substr($line, 0, 157).'...' : $line;
    }
}
