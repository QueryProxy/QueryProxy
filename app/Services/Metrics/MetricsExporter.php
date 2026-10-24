<?php

namespace App\Services\Metrics;

use App\Models\QueryRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Renders /metrics in the Prometheus text exposition format (version 0.0.4).
 *
 * Counters and histogram buckets are read from the database (MetricsRecorder
 * writes them); the gauges are computed here, at scrape time. A gauge that
 * cannot be computed is left out of the output rather than reported as 0.
 */
class MetricsExporter
{
    public const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    /** @var array<string, list<array{labels: array<string, string>, value: int}>> */
    private array $stored = [];

    public function render(): string
    {
        $this->load();

        $lines = [];

        foreach (Metric::cases() as $metric) {
            $family = match ($metric->type()) {
                'counter' => $this->counterLines($metric),
                'histogram' => $this->histogramLines($metric),
                default => $this->gaugeLines($metric),
            };

            if ($family === []) {
                continue;
            }

            $lines[] = '# HELP '.$metric->value.' '.$this->escapeHelp($metric->help());
            $lines[] = '# TYPE '.$metric->value.' '.$metric->type();
            array_push($lines, ...$family);
        }

        return implode("\n", $lines)."\n";
    }

    private function load(): void
    {
        $this->stored = [];

        foreach (DB::table('metric_samples')->orderBy('name')->orderBy('labels')->get(['name', 'labels', 'value']) as $row) {
            $labels = json_decode((string) $row->labels, true);

            if (is_array($labels)) {
                $this->stored[$row->name][] = ['labels' => array_map('strval', $labels), 'value' => (int) $row->value];
            }
        }
    }

    /**
     * @return list<string>
     */
    private function counterLines(Metric $metric): array
    {
        $series = [];

        foreach ($metric->zeroSeries() as $labels) {
            $series[$this->seriesKey($labels)] = ['labels' => $labels, 'value' => 0];
        }

        foreach ($this->stored[$metric->value] ?? [] as $sample) {
            $series[$this->seriesKey($sample['labels'])] = $sample;
        }

        ksort($series);

        return array_map(
            fn (array $sample) => $metric->value.$this->labelText($sample['labels']).' '.$sample['value'],
            array_values($series),
        );
    }

    /**
     * @return list<string>
     */
    private function histogramLines(Metric $metric): array
    {
        /** @var array<string, array{labels: array<string, string>, buckets: array<string, int>, sum: int}> $series */
        $series = [];

        foreach ($this->stored[$metric->value.'_bucket'] ?? [] as $sample) {
            $labels = $sample['labels'];
            $upper = (string) ($labels['le'] ?? '');
            unset($labels['le']);

            $key = $this->seriesKey($labels);
            $series[$key] ??= ['labels' => $labels, 'buckets' => [], 'sum' => 0];
            $series[$key]['buckets'][$upper] = $sample['value'];
        }

        foreach ($this->stored[$metric->value.'_sum'] ?? [] as $sample) {
            $key = $this->seriesKey($sample['labels']);
            $series[$key] ??= ['labels' => $sample['labels'], 'buckets' => [], 'sum' => 0];
            $series[$key]['sum'] = $sample['value'];
        }

        ksort($series);

        $lines = [];

        foreach ($series as $one) {
            $cumulative = 0;

            foreach ([...$metric->buckets(), '+Inf'] as $upper) {
                $cumulative += $one['buckets'][$upper] ?? 0;
                $lines[] = $metric->value.'_bucket'.$this->labelText([...$one['labels'], 'le' => $upper]).' '.$cumulative;
            }

            $lines[] = $metric->value.'_sum'.$this->labelText($one['labels']).' '.$this->seconds($one['sum']);
            $lines[] = $metric->value.'_count'.$this->labelText($one['labels']).' '.$cumulative;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function gaugeLines(Metric $metric): array
    {
        try {
            $samples = match ($metric) {
                Metric::RequestsPending => [[[], QueryRequest::pending()->count()]],
                Metric::QueueDepth => $this->queueDepths(),
                Metric::FailedJobs => $this->failedJobs(),
                default => [],
            };
        } catch (Throwable) {
            return [];
        }

        return array_map(
            fn (array $sample) => $metric->value.$this->labelText($sample[0]).' '.$sample[1],
            $samples,
        );
    }

    /**
     * @return list<array{0: array<string, string>, 1: int}>
     */
    private function queueDepths(): array
    {
        $queues = array_values(array_unique(array_filter([
            'default',
            'queries',
            (string) config('queryproxy.siem.queue', 'default'),
        ])));

        sort($queues);

        $samples = [];

        foreach ($queues as $queue) {
            try {
                $samples[] = [['queue' => $queue], (int) Queue::size($queue)];
            } catch (Throwable) {
                // This queue's backend is unreachable: no sample, not a false 0.
            }
        }

        return $samples;
    }

    /**
     * @return list<array{0: array<string, string>, 1: int}>
     */
    private function failedJobs(): array
    {
        $table = config('queue.failed.table');
        $connection = config('queue.failed.database');

        if (! is_string($table) || $table === '' || ! Schema::connection($connection)->hasTable($table)) {
            return [];
        }

        return [[[], DB::connection($connection)->table($table)->count()]];
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function labelText(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];

        foreach ($labels as $name => $value) {
            $pairs[] = $name.'="'.$this->escapeLabel((string) $value).'"';
        }

        return '{'.implode(',', $pairs).'}';
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function seriesKey(array $labels): string
    {
        ksort($labels);

        return (string) json_encode($labels);
    }

    private function seconds(int $milliseconds): string
    {
        return rtrim(rtrim(number_format($milliseconds / 1000, 3, '.', ''), '0'), '.') ?: '0';
    }

    private function escapeLabel(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    private function escapeHelp(string $text): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $text);
    }
}
