<?php

use App\Support\SqlServerReferentialActions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scim_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // SHA-256 of the token; the plaintext is shown once and never kept
            // (ADR-026, same pattern as chat_approval_tokens).
            $table->string('token_hash', 64)->unique();
            SqlServerReferentialActions::nullOnDelete($table, $table->foreignId('created_by')->nullable()->constrained('users'));
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scim_tokens');
    }
};
