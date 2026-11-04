<?php

namespace App\Jobs;

use App\Models\ChatIntegration;
use App\Services\Chat\SlackIdentitySuggester;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fills the identity suggestions of a Slack app installation in the
 * background: one Slack call per user does not belong in the install
 * redirect. Runs after the install and weekly (routes/console.php), so users
 * added later are covered too.
 *
 * One run looks at a batch of users. A full batch queues the next one from
 * where it ended; a Slack rate limit queues a retry after a minute from where
 * it stopped, a few times at most in a row without progress.
 */
class SuggestSlackIdentities implements ShouldQueue
{
    use Queueable;

    public const MAX_RATE_LIMIT_RETRIES = 5;

    public int $tries = 1;

    public function __construct(
        public int $chatIntegrationId,
        public int $afterUserId = 0,
        public int $rateLimitRetries = 0,
    ) {}

    public function handle(SlackIdentitySuggester $suggester): void
    {
        $integration = ChatIntegration::query()->with('team')->find($this->chatIntegrationId);

        if ($integration === null) {
            return;
        }

        $batch = $suggester->suggestBatch($integration, $this->afterUserId);

        // Only attempts in a row that got nowhere count: a batch that moved
        // the cursor before the rate limit hit made progress, so the counter
        // starts again. Otherwise a team larger than a few rate-limit windows
        // would never be covered to its end.
        $progressed = $batch['cursor'] > $this->afterUserId;
        $stalledRetries = $progressed ? 0 : $this->rateLimitRetries;

        if ($batch['rateLimited'] && $stalledRetries < self::MAX_RATE_LIMIT_RETRIES) {
            self::dispatch($this->chatIntegrationId, $batch['cursor'], $stalledRetries + 1)->delay(60);
        } elseif ($batch['more']) {
            self::dispatch($this->chatIntegrationId, $batch['cursor'])->delay(30);
        }
    }
}
