<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Masked column names a request filters on (WHERE / JOIN ON / HAVING /
     * GROUP BY / ORDER BY), as a JSON list. Null means "not analysed" (rows
     * from before this column existed, or detection failed); an empty list
     * means analysed with no hit. Existing rows are left null.
     */
    public function up(): void
    {
        Schema::table('query_requests', function (Blueprint $table) {
            $table->json('sensitive_filter_columns')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('query_requests', function (Blueprint $table) {
            $table->dropColumn('sensitive_filter_columns');
        });
    }
};
