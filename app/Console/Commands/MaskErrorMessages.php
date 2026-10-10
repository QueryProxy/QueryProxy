<?php

namespace App\Console\Commands;

use App\Models\Connection;
use App\Models\MaskingRule;
use App\Models\QueryRequest;
use App\Notifications\QueryRequestFinished;
use App\Services\Masking\ErrorMessageSanitizer;
use App\Services\Masking\Masker;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Re-applies the redact-and-mask path to error messages stored before failed
 * queries were masked: the message on the query request and the copy of it
 * inside "request failed" in-app notifications. Safe to run repeatedly: a
 * message that is already safe comes out unchanged and is not counted.
 */
class MaskErrorMessages extends Command
{
    protected $signature = 'queryproxy:mask-error-messages {--dry-run : Report how many messages would change without writing anything}';

    protected $description = 'Mask error messages stored on query requests and in failure notifications with each connection\'s current masking rules';

    /** @var array<int, Collection<int, MaskingRule>|null> */
    private array $rulesByConnection = [];

    public function handle(ErrorMessageSanitizer $sanitizer, Masker $masker): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->rulesByConnection = [];

        $scanned = 0;
        $changed = 0;
        $fixed = 0;
        $skipped = 0;
        $notificationsScanned = 0;
        $notificationsChanged = 0;
        $notificationsSkipped = 0;
        $notificationsFixed = 0;

        try {
            QueryRequest::query()
                ->whereNotNull('error_message')
                ->where('error_message', '!=', '')
                ->with('connection')
                ->eachById(function (QueryRequest $request) use ($sanitizer, $masker, $dryRun, &$scanned, &$changed, &$fixed, &$skipped) {
                    $scanned++;

                    $safe = $this->safeMessage($request, $sanitizer, $masker);

                    if ($safe === null) {
                        $skipped++;

                        return;
                    }

                    if ($safe === $request->error_message) {
                        return;
                    }

                    $changed++;

                    // Irreversible: the raw text is not kept anywhere else for
                    // records this old, so report these separately.
                    if ($safe === ErrorMessageSanitizer::FALLBACK_MESSAGE) {
                        $fixed++;
                    }

                    if (! $dryRun) {
                        // Straight to the query builder: this is a data fix, not a
                        // user edit, so updated_at stays as it was. The audit log is
                        // immutable and deliberately not rewritten here.
                        QueryRequest::whereKey($request->id)->toBase()->update(['error_message' => $safe]);
                    }
                }, 200);

            // Notifications copy the (first 120 characters of the) error
            // message, so they are rebuilt from the request's masked message
            // by the same rules. Order does not matter: the sanitizer is
            // idempotent, so a request not yet written (dry run) gives the
            // same result as one already written.
            DatabaseNotification::query()
                ->where('type', QueryRequestFinished::class)
                ->chunkById(200, function (Collection $notifications) use ($sanitizer, $masker, $dryRun, &$notificationsScanned, &$notificationsChanged, &$notificationsSkipped, &$notificationsFixed) {
                    $failed = $notifications->filter(fn (DatabaseNotification $n) => ($n->data['kind'] ?? null) === 'failed');

                    $requests = QueryRequest::query()
                        ->with('connection')
                        ->whereIn('id', $failed->map(fn (DatabaseNotification $n) => $n->data['query_request_id'] ?? null)->filter()->all())
                        ->get()
                        ->keyBy('id');

                    foreach ($failed as $notification) {
                        $data = $notification->data;

                        $notificationsScanned++;

                        $requestId = $data['query_request_id'] ?? null;
                        $request = $requestId !== null ? $requests->get($requestId) : null;

                        // No request (or no stored message) means nothing to
                        // verify the text against: the fixed message it is.
                        $safe = ErrorMessageSanitizer::FALLBACK_MESSAGE;

                        if ($request instanceof QueryRequest && filled($request->error_message)) {
                            $safe = $this->safeMessage($request, $sanitizer, $masker);

                            if ($safe === null) {
                                $notificationsSkipped++;

                                continue;
                            }
                        }

                        $message = ($requestId !== null ? sprintf('Request #%d failed: ', $requestId) : 'Request failed: ')
                            .str($safe)->limit(120);

                        if ($message === ($data['message'] ?? null)) {
                            continue;
                        }

                        $notificationsChanged++;

                        // Irreversible, like the request counterpart above.
                        if ($safe === ErrorMessageSanitizer::FALLBACK_MESSAGE) {
                            $notificationsFixed++;
                        }

                        if (! $dryRun) {
                            $data['message'] = $message;

                            // Query builder again: keeps updated_at and read_at.
                            DatabaseNotification::whereKey($notification->getKey())->toBase()->update(['data' => json_encode($data)]);
                        }
                    }
                });
        } finally {
            // Written even when the run dies halfway: earlier chunks are
            // already stored, and that change must leave a trace.
            if (! $dryRun) {
                audit()->record('error_messages.masked', metadata: [
                    'scanned' => $scanned,
                    'changed' => $changed,
                    'fixed_message' => $fixed,
                    'skipped' => $skipped,
                    'notifications_scanned' => $notificationsScanned,
                    'notifications_changed' => $notificationsChanged,
                    'notifications_fixed_message' => $notificationsFixed,
                    'notifications_skipped' => $notificationsSkipped,
                ]);
            }
        }

        $skippedTotal = $skipped + $notificationsSkipped;
        $summary = "{$changed} of {$scanned} error message(s) and {$notificationsChanged} of {$notificationsScanned} failure notification(s)";
        $detail = "{$fixed} message(s) and {$notificationsFixed} notification(s) replaced by the fixed message, {$skippedTotal} skipped";

        $this->info($dryRun
            ? "Dry run: {$summary} would be masked ({$detail})."
            : "Masked {$summary} ({$detail}).");

        return $skippedTotal > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The masked message for a request, or null when its connection's rules
     * cannot be read (the caller leaves the record untouched).
     */
    private function safeMessage(QueryRequest $request, ErrorMessageSanitizer $sanitizer, Masker $masker): ?string
    {
        $connection = $request->connection;
        $rules = null;

        if ($connection instanceof Connection) {
            if (! array_key_exists($connection->id, $this->rulesByConnection)) {
                try {
                    $this->rulesByConnection[$connection->id] = $masker->rulesFor($connection);
                } catch (Throwable $e) {
                    // Unreadable rules: leave the record untouched and say so,
                    // rather than guess. The command can be re-run once the
                    // rules are readable again.
                    $this->rulesByConnection[$connection->id] = null;
                    $reason = $e::class;
                    $this->warn("Rules of connection #{$connection->id} could not be read ({$reason}); its messages are skipped.");
                }
            }

            $rules = $this->rulesByConnection[$connection->id];

            if ($rules === null) {
                return null;
            }
        }

        return $sanitizer->sanitize((string) $request->error_message, $connection, $rules);
    }
}
