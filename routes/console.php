<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('results:prune')->daily();
Schedule::command('queue:prune-failed')->weekly();
