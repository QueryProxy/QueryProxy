<?php

namespace App\Console\Commands;

use App\Models\QueryRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneResults extends Command
{
    protected $signature = 'results:prune {--days= : Override queryproxy.result_ttl_days}';

    protected $description = 'Delete stored result files older than the configured retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('queryproxy.result_ttl_days', 30));
        $cutoff = now()->subDays($days);
        $pruned = 0;

        QueryRequest::query()
            ->whereNotNull('result_path')
            ->where('executed_at', '<', $cutoff)
            ->eachById(function (QueryRequest $request) use (&$pruned) {
                Storage::disk($request->result_disk)->delete($request->result_path);

                $request->update([
                    'result_disk' => null,
                    'result_path' => null,
                ]);

                $pruned++;
            });

        // Disk-scan fallback: catch files orphaned by DB cascades (deleted
        // teams / connections remove query_requests rows without firing
        // model events, leaving their NDJSON files unreachable above).
        $disk = Storage::disk(config('queryproxy.result_disk', 'local'));
        $orphaned = 0;

        foreach ($disk->allFiles('results') as $file) {
            $lastModified = $disk->lastModified($file);

            if ($lastModified < $cutoff->getTimestamp() && ! QueryRequest::where('result_path', $file)->exists()) {
                $disk->delete($file);
                $orphaned++;
            }
        }

        $this->info("Pruned {$pruned} result file(s) older than {$days} days ({$orphaned} orphaned).");

        return self::SUCCESS;
    }
}
