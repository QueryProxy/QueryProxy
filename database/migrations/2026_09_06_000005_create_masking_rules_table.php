<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('masking_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('match_type');
            $table->string('pattern');
            $table->string('strategy');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masking_rules');
    }
};
