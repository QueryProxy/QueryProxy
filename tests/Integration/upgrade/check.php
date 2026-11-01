<?php

/*
 * Run on the NEW image after the upgrade (piped into `php` by run.sh): the
 * rows seed.php created on the old image must still be there. The hash chain
 * itself is checked separately with `php artisan audit:verify`.
 */

use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$problems = [];

if (Team::where('slug', 'upgrade-team')->count() !== 1) {
    $problems[] = 'the seeded team is missing';
}

if (User::where('email', 'upgrade-user@example.com')->count() !== 1) {
    $problems[] = 'the seeded user is missing';
}

if (User::where('email', 'upgrade-admin@example.com')->where('is_admin', true)->count() !== 1) {
    $problems[] = 'the administrator created on the old image is missing';
}

$seeded = AuditLog::where('action', 'like', 'upgrade.seed.%')->count();

if ($seeded !== 5) {
    $problems[] = "expected 5 seeded audit records, found {$seeded}";
}

if ($problems !== []) {
    fwrite(STDERR, 'UPGRADE CHECK FAILED: '.implode('; ', $problems).PHP_EOL);
    exit(1);
}

echo 'UPGRADE CHECK OK audit_total='.AuditLog::count().PHP_EOL;
