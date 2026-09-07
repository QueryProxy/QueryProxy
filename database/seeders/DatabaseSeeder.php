<?php

namespace Database\Seeders;

use App\Enums\TeamRole;
use App\Models\MaskingRule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed a demo environment: one admin, one team, one user per role.
     * All demo accounts share one random password, printed once below.
     *
     * No factories here on purpose: this seeder runs inside the production
     * container (QUERYPROXY_SEED_DEMO=true), where dev-only packages such as
     * Faker are not installed.
     */
    public function run(): void
    {
        if (app()->environment('production') && env('QUERYPROXY_SEED_DEMO_FORCE') !== 'true') {
            throw new RuntimeException(
                'Refusing to seed demo accounts in production. Set QUERYPROXY_SEED_DEMO_FORCE=true '
                .'alongside QUERYPROXY_SEED_DEMO=true if you really want demo data on this instance.',
            );
        }

        $password = Str::password(16);

        User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => $password,
            'is_admin' => true,
        ]);

        $team = Team::create(['name' => 'Demo Team', 'slug' => 'demo-team']);

        $members = [
            ['Dana DBA', 'dba@example.com', TeamRole::Dba],
            ['Devin Developer', 'developer@example.com', TeamRole::Developer],
            ['Audrey Auditor', 'auditor@example.com', TeamRole::Auditor],
        ];

        foreach ($members as [$name, $email, $role]) {
            $team->users()->attach(
                User::create(['name' => $name, 'email' => $email, 'password' => $password]),
                ['role' => $role->value],
            );
        }

        foreach (MaskingRule::defaults() as $default) {
            MaskingRule::create($default + ['team_id' => $team->id]);
        }

        $this->command?->warn('Demo accounts created (admin@example.com, dba@example.com, developer@example.com, auditor@example.com).');
        $this->command?->warn("Demo password (shown only once): {$password}");
    }
}
