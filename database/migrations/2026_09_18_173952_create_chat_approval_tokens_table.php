<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_approval_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_request_id')->constrained()->cascadeOnDelete();

            // Only the hash is stored: the plaintext lives in the chat message
            // we sent, and a leaked database row must not be replayable.
            $table->string('token_hash')->index();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_approval_tokens');
    }
};
