<?php

use App\Services\Doctor\SchedulerHeartbeat;
use Illuminate\Support\Facades\Schedule;

Schedule::command('results:prune')->daily();
Schedule::command('queue:prune-failed')->weekly();
Schedule::command('audit:checkpoint')->daily();
Schedule::command('requests:remind')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('connections:expire-grants')->everyMinute()->withoutOverlapping();

// Liveness signal for queryproxy:doctor.
Schedule::call(fn () => SchedulerHeartbeat::beat())->name('scheduler-heartbeat')->everyMinute();
