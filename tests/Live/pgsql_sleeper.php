<?php

/*
 * Helper process for PgsqlCancelTest: opens its own PostgreSQL session from
 * the QUERYPROXY_LIVE_PGSQL_* environment, reports its backend PID and then
 * runs pg_sleep(), so the test can stop it from another session. Not a test
 * file; it does not boot the application.
 *
 *   php tests/Live/pgsql_sleeper.php [seconds]
 *
 * Output, one line each: "PID <n>", "STAMP <backend_start>" (the session's
 * start stamp as pg_stat_activity reports it), then "DONE" when the sleep ran
 * to the end or "ERROR <sqlstate> <message>" when the server stopped it.
 */

$env = fn (string $key, string $default = ''): string => (getenv('QUERYPROXY_LIVE_PGSQL_'.$key) ?: $default);

$seconds = max(1, (int) ($argv[1] ?? 30));

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $env('HOST'), $env('PORT', '5432'), $env('DATABASE', 'postgres')),
    $env('USERNAME', 'postgres'),
    $env('PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

fwrite(STDOUT, 'PID '.$pdo->query('SELECT pg_backend_pid()')->fetchColumn()."\n");
fwrite(STDOUT, 'STAMP '.$pdo->query('SELECT backend_start::text FROM pg_stat_activity WHERE pid = pg_backend_pid()')->fetchColumn()."\n");
fflush(STDOUT);

try {
    $pdo->query('SELECT pg_sleep('.$seconds.')')->fetchAll();
    fwrite(STDOUT, "DONE\n");
} catch (PDOException $e) {
    fwrite(STDOUT, 'ERROR '.$e->getCode().' '.str_replace("\n", ' ', $e->getMessage())."\n");
}

fflush(STDOUT);
