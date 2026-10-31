<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\QueryRequest;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The stored result of a completed request. The file was masked when it was
 * written and is streamed from storage, never loaded whole (ADR-003).
 */
class RequestResultController extends Controller
{
    private const NDJSON = 'application/x-ndjson';

    private const CSV = 'text/csv';

    /**
     * NDJSON by default, one JSON array per row in column order (the column
     * names are in the request resource); `Accept: text/csv` asks for CSV.
     * Auditors get 403 (ADR-009), like on the web download.
     */
    public function __invoke(Request $request, QueryRequest $queryRequest): StreamedResponse
    {
        Gate::forUser($request->user())->authorize('viewResult', $queryRequest);

        abort_unless($queryRequest->hasResult(), 409, 'This request has no stored result (status: '.$queryRequest->status->value.').');

        $disk = Storage::disk($queryRequest->result_disk);

        abort_unless($disk->exists($queryRequest->result_path), 404, 'The stored result is no longer available.');

        $format = $request->prefers([self::NDJSON, self::CSV]) === self::CSV ? self::CSV : self::NDJSON;

        audit()->record('result.downloaded', request: $queryRequest, actor: $request->user(), metadata: [
            'channel' => 'api',
            'format' => $format === self::CSV ? 'csv' : 'ndjson',
        ]);

        $headers = [
            'Content-Type' => $format,
            'X-QueryProxy-Row-Count' => (string) (int) $queryRequest->result_row_count,
            'X-QueryProxy-Truncated' => $queryRequest->result_truncated ? 'true' : 'false',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return response()->stream(function () use ($queryRequest, $disk, $format) {
            $stream = $disk->readStream($queryRequest->result_path);

            if ($stream === null) {
                return;
            }

            $out = fopen('php://output', 'w');

            if ($format === self::NDJSON) {
                stream_copy_to_stream($stream, $out);
            } else {
                Csv::writeFromNdjson($stream, $out, $queryRequest->result_columns ?? []);
            }

            fclose($stream);
            fclose($out);
        }, 200, $headers);
    }
}
