<?php

namespace App\Jobs;

use App\Enums\QueryRequestStatus;
use App\Models\QueryRequest;
use App\Services\Execution\QueryExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteQueryRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public QueryRequest $request)
    {
        $this->onQueue('queries');
        $this->timeout = (int) config('queryproxy.execution_timeout', 300);
    }

    public function handle(QueryExecutor $executor): void
    {
        $executor->execute($this->request->fresh());
    }

    /** Safety net: mark the request failed if the job dies outside execute(). */
    public function failed(?Throwable $exception): void
    {
        $request = $this->request->fresh();

        if ($request && ! $request->status->isFinal()) {
            $request->update([
                'status' => QueryRequestStatus::Failed,
                'error_message' => $exception?->getMessage() ?? 'Job failed unexpectedly.',
            ]);
        }
    }
}
