<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->text('webhook_url');
            $table->text('signing_secret');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['team_id', 'provider']);
        });

        Schema::create('chat_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_id');
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_identities');
        Schema::dropIfExists('chat_integrations');
    }
};
