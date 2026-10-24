<?php

namespace App\Jobs;

use App\Enums\QueryRequestStatus;
use App\Jobs\Middleware\LimitConnectionConcurrency;
use App\Models\QueryRequest;
use App\Services\Approvals\ApprovalNotifier;
use App\Services\Execution\BackendCanceller;
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
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new LimitConnectionConcurrency];
    }

    /**
     * Put this request back on the queue to start again after $delay seconds
     * and drop the current copy.
     *
     * release() is not used: it counts as an attempt, and with tries = 1 the
     * next pick-up would be failed as having exceeded them. A fresh copy
     * starts at zero attempts. Dispatching before deleting means a crash in
     * between leaves two copies, which the executor's conditional claim
     * already handles (only one can start the request).
     */
    public function requeue(int $delay): void
    {
        $copy = new self($this->request);
        $copy->onConnection($this->connection)->onQueue($this->queue)->delay($delay);

        dispatch($copy);

        $this->job?->delete();
    }

    /**
     * Safety net: mark the request failed if the job dies outside execute().
     * Conditional update so a duplicate job copy can never flip a request
     * another worker already completed.
     *
     * The stored message goes through the same redact-and-mask path as
     * execute()'s own failures; the raw exception text only reaches the log.
     * When the update wins, the statement still running on the target (if its
     * session was recorded) is cancelled, and the failure is audited and the
     * requester notified, exactly as execute() would have done.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Query job failed.', [
            'query_request_id' => $this->request->id,
            'exception' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);

        $message = $this->safeMessage($exception);

        // The session the run was using on the target, read before the update
        // below clears it.
        $session = QueryRequest::whereKey($this->request->id)
            ->where('status', QueryRequestStatus::Running)
            ->first(['id', 'connection_id', 'type', 'backend_pid', 'backend_session']);

        $updated = QueryRequest::whereKey($this->request->id)
            ->whereIn('status', [
                QueryRequestStatus::Queued,
                QueryRequestStatus::Approved,
                QueryRequestStatus::Running,
            ])
            ->update([
                'status' => QueryRequestStatus::Failed,
                'backend_pid' => null,
                'backend_session' => null,
                'error_message' => $message,
            ]);

        if ($updated === 0) {
            return;
        }

        // The worker is gone (or about to be) but the statement may still be
        // running on the target, holding locks and a connection slot until the
        // database's own timeout. Cancel it the way a user's stop does.
        $this->cancelOnTarget($session);

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
     * Best effort: ask the target to stop the statement of a run this job
     * has just failed. Never throws; failed() must go on to audit and notify.
     */
    private function cancelOnTarget(?QueryRequest $session): void
    {
        if ($session === null || $session->backend_pid === null) {
            return;
        }

        try {
            app(BackendCanceller::class)->cancel(
                $session->connection,
                $session->backend_pid,
                $session->backend_session,
                $session->runsAsReader(),
            );
        } catch (Throwable $e) {
            Log::warning('Could not cancel the statement of a failed job on the target.', [
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
