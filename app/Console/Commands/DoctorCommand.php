<?php

namespace App\Console\Commands;

use App\Services\Doctor\CheckResult;
use App\Services\Doctor\CheckStatus;
use App\Services\Doctor\Doctor;
use Illuminate\Console\Command;

class DoctorCommand extends Command
{
    protected $signature = 'queryproxy:doctor
        {--strict : Exit with 1 on warnings too, not only on failures}
        {--full-audit : Verify the whole audit chain instead of its newest records}
        {--json : Print the results as JSON}';

    protected $description = 'Check the installation: configuration, database, queue, scheduler, storage, TLS, audit chain';

    public function handle(): int
    {
        $results = Doctor::default((bool) $this->option('full-audit'))->run();

        $this->option('json') ? $this->renderJson($results) : $this->renderTable($results);

        return $this->exitCode($results);
    }

    /**
     * One rule for the exit code and for `ok` in the JSON: a failure, or a warning under --strict.
     *
     * @param  list<CheckResult>  $results
     */
    private function exitCode(array $results): int
    {
        $failed = $this->count($results, CheckStatus::Fail);
        $warned = $this->count($results, CheckStatus::Warn);

        return $failed > 0 || ($warned > 0 && $this->option('strict')) ? self::FAILURE : self::SUCCESS;
    }

    /** @param  list<CheckResult>  $results */
    private function renderTable(array $results): void
    {
        $this->table(
            ['Status', 'Check', 'Detail'],
            array_map(fn (CheckResult $result) => [
                $this->colored($result->status),
                $result->check,
                $result->message,
            ], $results),
        );

        $failed = $this->count($results, CheckStatus::Fail);
        $warned = $this->count($results, CheckStatus::Warn);
        $summary = count($results).' checks: '.$this->count($results, CheckStatus::Ok).' ok, '
            ."{$warned} warning(s), {$failed} failure(s), ".$this->count($results, CheckStatus::Skip).' skipped.';

        if ($failed > 0) {
            $this->error($summary);
        } elseif ($warned > 0) {
            $this->warn($summary.($this->option('strict') ? '' : ' (warnings do not fail the run; use --strict to make them)'));
        } else {
            $this->info($summary);
        }
    }

    /** @param  list<CheckResult>  $results */
    private function renderJson(array $results): void
    {
        $this->line((string) json_encode([
            'ok' => $this->exitCode($results) === self::SUCCESS,
            'strict' => (bool) $this->option('strict'),
            'checks' => array_map(fn (CheckResult $result) => $result->toArray(), $results),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function colored(CheckStatus $status): string
    {
        $color = match ($status) {
            CheckStatus::Ok => 'green',
            CheckStatus::Warn => 'yellow',
            CheckStatus::Fail => 'red',
            CheckStatus::Skip => 'gray',
        };

        return "<fg={$color}>{$status->label()}</>";
    }

    /** @param  list<CheckResult>  $results */
    private function count(array $results, CheckStatus $status): int
    {
        return count(array_filter($results, fn (CheckResult $result) => $result->status === $status));
    }
}
