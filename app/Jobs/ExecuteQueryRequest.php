<?php

namespace App\Jobs;

use App\Enums\QueryRequestStatus;
use App\Models\QueryRequest;
use App\Services\Approvals\ApprovalNotifier;
use App\Services\Execution\QueryExecutor;
use App\Services\Masking\ErrorMessageSanitizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
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

    /**
     * Safety net: mark the request failed if the job dies outside execute().
     * Conditional update so a duplicate job copy can never flip a request
     * another worker already completed.
     *
     * The stored message goes through the same redact-and-mask path as
     * execute()'s own failures; the raw exception text only reaches the log.
     * When the update wins, the failure is audited and the requester notified,
     * exactly as execute() would have done.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Query job failed.', [
            'query_request_id' => $this->request->id,
            'exception' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);

        $message = $this->safeMessage($exception);

        $updated = QueryRequest::whereKey($this->request->id)
            ->whereIn('status', [
                QueryRequestStatus::Queued,
                QueryRequestStatus::Approved,
                QueryRequestStatus::Running,
            ])
            ->update([
                'status' => QueryRequestStatus::Failed,
                'error_message' => $message,
            ]);

        if ($updated === 0) {
            return;
        }

        try {
            $request = QueryRequest::findOrFail($this->request->id);

            audit()->record('request.execution_failed', actor: $request->reviewer, request: $request, metadata: [
                'error' => $message,
                'source' => 'job_failed',
            ]);

            ApprovalNotifier::requestFinished($request);
        } catch (Throwable $e) {
            Log::error('Recording a failed job request failed.', [
                'query_request_id' => $this->request->id,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Redacted and masked message, or the fixed one if that cannot be done.
     */
    private function safeMessage(?Throwable $exception): string
    {
        try {
            return app(ErrorMessageSanitizer::class)->sanitize(
                $exception ?? 'Job failed unexpectedly.',
                $this->request->connection,
            );
        } catch (Throwable) {
            return ErrorMessageSanitizer::FALLBACK_MESSAGE;
        }
    }
}
