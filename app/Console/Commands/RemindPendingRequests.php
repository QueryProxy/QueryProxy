<?php

namespace App\Console\Commands;

use App\Models\ChatIntegration;
use App\Models\QueryRequest;
use App\Services\Approvals\ApprovalNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Keeps reviewers aware of requests nobody has decided on. Requests are never
 * expired (D-01). Two independent duties:
 *
 *  - the reminder goes out once, QUERYPROXY_PENDING_REMIND_AFTER hours after
 *    the request was filed (0 turns it off);
 *  - whenever a pending request has no live chat token left, the card is posted
 *    again with fresh tokens, at most once per renewal window. This runs even
 *    when reminders are off, because an expired button points reviewers to the
 *    "newer message" and that message has to exist.
 */
class RemindPendingRequests extends Command
{
    /** Minimum gap between two renewals; matches the chat token lifetime. */
    private const RENEWAL_INTERVAL_HOURS = 24;

    protected $signature = 'requests:remind';

    protected $description = 'Remind reviewers about pending requests and refresh expired chat cards';

    public function handle(): int
    {
        $hours = (int) config('queryproxy.pending_remind_after_hours', 4);

        $reminded = $hours > 0 ? $this->sendReminders($hours) : 0;
        $renewed = $this->renewExpiredCards();

        $this->info("Reminded about {$reminded} pending request(s); renewed {$renewed} expired chat card(s).");

        return self::SUCCESS;
    }

    private function sendReminders(int $hours): int
    {
        $reminded = 0;

        QueryRequest::query()
            ->pending()
            ->whereNull('reminded_at')
            ->where('created_at', '<=', now()->subHours($hours))
            ->eachById(function (QueryRequest $request) use (&$reminded) {
                // Claim before sending: two overlapping runs cannot both win
                // the conditional update, so the reminder goes out once.
                if (! $this->claim($request, 'reminded_at', null)) {
                    return;
                }

                ApprovalNotifier::requestReminder($request->fresh());
                $reminded++;
            });

        return $reminded;
    }

    /**
     * Pending, in a team that has an enabled integration, and every chat token
     * has timed out: the buttons in chat are dead, so post them again.
     * `chat_renewed_at` holds the last renewal and spaces them out.
     */
    private function renewExpiredCards(): int
    {
        $renewed = 0;
        $notRenewedSince = now()->subHours(self::RENEWAL_INTERVAL_HOURS);

        QueryRequest::query()
            ->pending()
            ->whereIn('team_id', ChatIntegration::query()->where('enabled', true)->select('team_id'))
            ->where(fn ($query) => $query
                ->whereNull('chat_renewed_at')
                ->orWhere('chat_renewed_at', '<=', $notRenewedSince))
            ->whereDoesntHave('chatApprovalTokens', fn ($query) => $query
                ->where('expires_at', '>', now())
                ->whereNull('used_at'))
            ->eachById(function (QueryRequest $request) use (&$renewed, $notRenewedSince) {
                if (! $this->claim($request, 'chat_renewed_at', $notRenewedSince)) {
                    return;
                }

                ApprovalNotifier::chatCardRenewed($request->fresh());
                $renewed++;
            });

        return $renewed;
    }

    /**
     * Stamp the column iff the request is still pending and the column is still
     * empty (or, with $staleBefore, was last stamped at or before that moment).
     */
    private function claim(QueryRequest $request, string $column, ?Carbon $staleBefore): bool
    {
        return QueryRequest::query()
            ->whereKey($request->id)
            ->pending()
            ->where(fn ($query) => $query
                ->whereNull($column)
                ->when($staleBefore, fn ($query) => $query->orWhere($column, '<=', $staleBefore)))
            ->update([$column => now()]) === 1;
    }
}
