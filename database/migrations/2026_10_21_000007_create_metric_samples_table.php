<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The counters and histogram buckets behind /metrics (ADR-022).
     *
     * They live in the application database so every web and worker process
     * adds to the same value; Redis is not required (ADR-002). One row is one
     * time series: `name` is the sample name, `labels` the sorted label set as
     * JSON. Histograms use one row per bucket (not cumulative; the exporter
     * accumulates) plus one `_sum` row in milliseconds.
     *
     * Only enumerated label values are ever written (see MetricsRecorder), so
     * the table stays small and never holds SQL, values, users or teams.
     */
    public function up(): void
    {
        Schema::create('metric_samples', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('labels', 190)->default('{}');
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['name', 'labels']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_samples');
    }
};
