<?php

namespace App\Jobs;

use App\Models\QueryRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

    public function handle(): void
    {
        // Implemented in Phase 5 (async execution engine).
    }
}
