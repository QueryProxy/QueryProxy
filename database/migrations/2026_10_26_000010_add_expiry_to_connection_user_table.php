<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing rows stay permanent (`expires_at` null) and full-access
     * (`access_level` write), so nobody gains or loses access (ADR-025).
     */
    public function up(): void
    {
        Schema::table('connection_user', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->index();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->string('access_level', 10)->default('write');
        });
    }

    /**
     * The columns are the only record of a grant's end date and read-only
     * level. Dropping them would turn every timed grant permanent and every
     * read grant into a write grant, so the rollback stops instead.
     */
    public function down(): void
    {
        $blocked = DB::table('connection_user')
            ->where(fn ($query) => $query->whereNotNull('expires_at')->orWhere('access_level', '!=', 'write'))
            ->count();

        if ($blocked > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$blocked} grant(s) are time-bound or read-only and would become permanent write grants. "
                .'Revoke them first, then roll back.'
            );
        }

        Schema::table('connection_user', function (Blueprint $table) {
            $table->dropForeign(['granted_by']);
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['expires_at', 'granted_by', 'reason', 'access_level']);
        });
    }
};
