<?php

namespace Database\Seeders;

use App\Enums\TeamRole;
use App\Models\MaskingRule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed a demo environment: one admin, one team, one user per role.
     * All demo accounts use the password "password".
     *
     * No factories here on purpose: this seeder runs inside the production
     * container (QUERYPROXY_SEED_DEMO=true), where dev-only packages such as
     * Faker are not installed.
     */
    public function run(): void
    {
        User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
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
                User::create(['name' => $name, 'email' => $email, 'password' => 'password']),
                ['role' => $role->value],
            );
        }

        foreach (MaskingRule::defaults() as $default) {
            MaskingRule::create($default + ['team_id' => $team->id]);
        }
    }
}
