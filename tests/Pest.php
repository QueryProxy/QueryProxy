<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// Live database checks. The PostgreSQL guard tests ("live-pgsql") are skipped
// unless QUERYPROXY_LIVE_PGSQL_HOST is set; the audit chain tests
// ("live-appdb") unless QUERYPROXY_LIVE_APPDB_HOST is set.
pest()->extend(TestCase::class)->in('Live');

pest()->group('live-pgsql')->in('Live/Pgsql*');

pest()->group('live-appdb')->in('Live/AuditChainLiveTest.php');
