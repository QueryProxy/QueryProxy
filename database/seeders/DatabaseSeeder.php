<?php

namespace Database\Seeders;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed a demo environment: one admin, one team, one user per role.
     * All demo accounts use the password "password".
     */
    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'is_admin' => true,
        ]);

        $team = Team::create(['name' => 'Demo Team', 'slug' => 'demo-team']);

        $team->users()->attach(
            User::factory()->create(['name' => 'Dana DBA', 'email' => 'dba@example.com']),
            ['role' => TeamRole::Dba->value],
        );

        $team->users()->attach(
            User::factory()->create(['name' => 'Devin Developer', 'email' => 'developer@example.com']),
            ['role' => TeamRole::Developer->value],
        );

        $team->users()->attach(
            User::factory()->create(['name' => 'Audrey Auditor', 'email' => 'auditor@example.com']),
            ['role' => TeamRole::Auditor->value],
        );
    }
}
