<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('driver');
            // Credential fields hold Laravel-encrypted payloads (AES-256), hence TEXT.
            $table->text('host')->nullable();
            $table->text('port')->nullable();
            $table->text('database');
            $table->text('username')->nullable();
            $table->text('password')->nullable();
            $table->json('options')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'name']);
        });

        Schema::create('connection_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['connection_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_user');
        Schema::dropIfExists('connections');
    }
};
