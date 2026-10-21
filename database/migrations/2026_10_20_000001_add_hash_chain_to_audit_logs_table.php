<?php

use App\Services\Audit\AuditChain;
use App\Services\Audit\AuditTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Safe to re-run after a partial failure: MySQL and MariaDB commit DDL
     * implicitly, so a failed CREATE TRIGGER (it needs the TRIGGER privilege,
     * and SUPER when binary logging is on without log_bin_trust_function_
     * creators) leaves the earlier steps in place. Every step checks first.
     */
    public function up(): void
    {
        if (! Schema::hasTable(AuditChain::LOCK_TABLE)) {
            Schema::create(AuditChain::LOCK_TABLE, function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                // Chained records written so far, and the hash of the last one.
                $table->unsignedBigInteger('sequence')->default(0);
                $table->string('head_hash', 64)->nullable();
            });
        }

        if (! DB::table(AuditChain::LOCK_TABLE)->where('id', 1)->exists()) {
            DB::table(AuditChain::LOCK_TABLE)->insert(['id' => 1, 'sequence' => 0]);
        }

        foreach (['prev_hash', 'hash'] as $column) {
            if (! Schema::hasColumn('audit_logs', $column)) {
                Schema::table('audit_logs', fn (Blueprint $table) => $table->string($column, 64)->nullable());
            }
        }

        // A half-installed trigger set would reject the backfill's UPDATEs.
        $triggers = new AuditTriggers;
        $triggers->drop();

        // Existing records are chained from the genesis value, before the
        // triggers that forbid UPDATE exist. Also sets the lock row's count
        // and head.
        (new AuditChain)->backfill();

        // Unique after the backfill (a nullable unique column accepts only
        // one NULL on SQL Server). A unique prev_hash also makes a fork of
        // the chain impossible at the database level.
        foreach (['prev_hash', 'hash'] as $column) {
            if (! Schema::hasIndex('audit_logs', [$column], 'unique')) {
                Schema::table('audit_logs', fn (Blueprint $table) => $table->unique($column));
            }
        }

        $triggers->install();
    }

    public function down(): void
    {
        (new AuditTriggers)->drop();

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropUnique(['prev_hash']);
            $table->dropUnique(['hash']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['prev_hash', 'hash']);
        });

        Schema::dropIfExists(AuditChain::LOCK_TABLE);
    }
};
