<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'users_auth_provider_external_id_unique';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A user who signs in through the identity provider may have no
            // local password at all.
            $table->string('password')->nullable()->change();

            $table->string('auth_provider', 16)->default('local')->after('password');
            // The provider subject is only unique within its issuer, so the
            // issuer is stored with it and is part of the identity.
            $table->string('external_issuer')->nullable()->after('auth_provider');
            $table->string('external_id')->nullable()->after('external_issuer');
        });

        if (DB::getDriverName() === 'sqlsrv') {
            // SQL Server treats NULLs as equal in a unique index, so every
            // local user (external_id NULL) would collide: index only the
            // rows that carry an external id.
            DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON users (auth_provider, external_issuer, external_id) WHERE external_id IS NOT NULL');

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique(['auth_provider', 'external_issuer', 'external_id'], self::INDEX);
        });
    }

    /**
     * Accounts without a local password cannot exist once the column is NOT
     * NULL again, and giving them one would be a silent change of how they
     * sign in, so the rollback stops instead.
     */
    public function down(): void
    {
        $passwordless = DB::table('users')->whereNull('password')->count();

        if ($passwordless > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$passwordless} user account(s) have no local password (they sign in through SSO). "
                .'Give them a password or remove them, then roll back.'
            );
        }

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('DROP INDEX '.self::INDEX.' ON users');
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(self::INDEX);
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['auth_provider', 'external_issuer', 'external_id']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
