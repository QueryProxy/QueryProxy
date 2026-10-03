<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// Live database checks: skipped unless QUERYPROXY_LIVE_PGSQL_HOST is set.
pest()->extend(TestCase::class)
    ->group('live-pgsql')
    ->in('Live');
