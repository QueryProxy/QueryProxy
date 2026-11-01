<?php

use App\Support\SqlServerReferentialActions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_grant_requests', function (Blueprint $table) {
            $table->id();
            SqlServerReferentialActions::cascadeOnDelete($table, $table->foreignId('team_id')->constrained());
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('access_level', 10);
            $table->unsignedInteger('duration_minutes');
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            SqlServerReferentialActions::nullOnDelete($table, $table->foreignId('decided_by')->nullable()->constrained('users'));
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['connection_id', 'user_id', 'status']);
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_grant_requests');
    }
};
