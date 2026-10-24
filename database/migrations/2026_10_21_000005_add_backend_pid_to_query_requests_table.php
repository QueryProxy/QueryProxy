<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The target database's own id for the session a running request executes
     * on (pg_backend_pid(), CONNECTION_ID(), @@SPID). It is set while the
     * request runs, so a user can stop the statement from a second connection,
     * and cleared when the run settles. Null for every request that is not
     * running right now (and always null on SQLite, which has no such id).
     */
    public function up(): void
    {
        Schema::table('query_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('backend_pid')->nullable()->after('executed_at');
            $table->string('backend_session', 64)->nullable()->after('backend_pid');
        });
    }

    public function down(): void
    {
        Schema::table('query_requests', function (Blueprint $table) {
            $table->dropColumn(['backend_pid', 'backend_session']);
        });
    }
};
