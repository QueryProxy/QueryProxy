<?php

/*
 * Creates data on an OLD QueryProxy image (the release being upgraded from):
 * a team, a user and a short audit chain. Piped into `php` inside the
 * container by run.sh; it only uses what 0.3.x has (models, no factories).
 * check.php looks for the same rows after the upgrade.
 */

use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$team = Team::create(['name' => 'Upgrade Team', 'slug' => 'upgrade-team']);

User::forceCreate([
    'name' => 'Upgrade User',
    'email' => 'upgrade-user@example.com',
    'password' => Hash::make(bin2hex(random_bytes(16))),
]);

foreach (range(1, 5) as $i) {
    AuditLog::create(['team_id' => $team->id, 'action' => "upgrade.seed.{$i}"]);
}

echo 'SEEDED team='.$team->id.' audit_total='.AuditLog::count().PHP_EOL;
