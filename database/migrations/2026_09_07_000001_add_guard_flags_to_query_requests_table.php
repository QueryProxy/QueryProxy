<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('query_requests', function (Blueprint $table) {
            $table->boolean('is_ddl')->default(false)->after('is_transaction');
            $table->boolean('result_truncated')->default(false)->after('result_row_count');
        });
    }

    public function down(): void
    {
        Schema::table('query_requests', function (Blueprint $table) {
            $table->dropColumn(['is_ddl', 'result_truncated']);
        });
    }
};
