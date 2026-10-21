<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('is_admin');
            $table->softDeletes();
        });
    }

    /**
     * Soft delete and suspension live only in these columns. Dropping them
     * while a deleted or suspended account exists would silently re-enable
     * that account (its password is untouched), so the rollback stops instead.
     */
    public function down(): void
    {
        $blocked = DB::table('users')
            ->where(fn ($query) => $query->whereNotNull('deleted_at')->orWhereNotNull('suspended_at'))
            ->count();

        if ($blocked > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$blocked} user account(s) are deleted or suspended and would become active again. "
                .'Hard delete them (after removing their requests) or lift the suspension, then roll back.'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('suspended_at');
        });
    }
};
