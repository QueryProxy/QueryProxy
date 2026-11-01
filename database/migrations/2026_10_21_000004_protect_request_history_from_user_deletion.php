<?php

use App\Support\SqlServerReferentialActions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user can no longer take their request history with them: removing a users
 * row is refused while query_requests still point at it (as requester or as
 * reviewer). Accounts are retired with soft delete instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('query_requests', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['reviewed_by']);
        });

        Schema::table('query_requests', function (Blueprint $table) {
            SqlServerReferentialActions::restrictOnDelete($table->foreign('user_id')->references('id')->on('users'));
            SqlServerReferentialActions::restrictOnDelete($table->foreign('reviewed_by')->references('id')->on('users'));
        });
    }

    public function down(): void
    {
        Schema::table('query_requests', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['reviewed_by']);
        });

        Schema::table('query_requests', function (Blueprint $table) {
            SqlServerReferentialActions::cascadeOnDelete($table, $table->foreign('user_id')->references('id')->on('users'));
            SqlServerReferentialActions::nullOnDelete($table, $table->foreign('reviewed_by')->references('id')->on('users'));
        });
    }
};
