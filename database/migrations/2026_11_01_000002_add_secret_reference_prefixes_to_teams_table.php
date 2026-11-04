<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // Reference prefixes the team may use for connection passwords
            // (ADR-028 amendment), as a JSON list such as
            // ["vault://secret/teams/payments"]. NULL or an empty list means the
            // team cannot use external secret references at all.
            $table->json('secret_reference_prefixes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('secret_reference_prefixes');
        });
    }
};
