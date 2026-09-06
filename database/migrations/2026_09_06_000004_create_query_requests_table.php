<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('sql_original');
            $table->text('sql_prepared');
            $table->unsignedInteger('statement_count')->default(1);
            $table->boolean('is_transaction')->default(false);
            $table->string('type');
            $table->string('status')->default('pending')->index();
            $table->text('note')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('review_channel')->nullable();

            $table->timestamp('executed_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->bigInteger('affected_rows')->nullable();

            $table->string('result_disk')->nullable();
            $table->string('result_path')->nullable();
            $table->unsignedBigInteger('result_row_count')->nullable();
            $table->json('result_columns')->nullable();

            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_requests');
    }
};
