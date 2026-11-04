<?php

use App\Enums\ChatProvider;
use App\Jobs\SuggestSlackIdentities;
use App\Models\ChatIntegration;
use App\Services\Doctor\SchedulerHeartbeat;
use Illuminate\Support\Facades\Schedule;

Schedule::command('results:prune')->daily();
Schedule::command('queue:prune-failed')->weekly();
Schedule::command('audit:checkpoint')->daily();
Schedule::command('requests:remind')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('connections:expire-grants')->everyMinute()->withoutOverlapping();

// Slack identity suggestions (ADR-027) for users added since the last look.
Schedule::call(function (): void {
    ChatIntegration::query()
        ->where('provider', ChatProvider::Slack->value)
        ->whereNotNull('bot_token')
        ->pluck('id')
        ->each(fn (int $id) => SuggestSlackIdentities::dispatch($id));
})->name('slack-identity-suggestions')->weekly();

// Liveness signal for queryproxy:doctor.
Schedule::call(fn () => SchedulerHeartbeat::beat())->name('scheduler-heartbeat')->everyMinute();
