<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('results:prune')->daily();
Schedule::command('queue:prune-failed')->weekly();
Schedule::command('audit:checkpoint')->daily();
Schedule::command('requests:remind')->everyFifteenMinutes()->withoutOverlapping();
