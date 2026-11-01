<?php

namespace App\Http\Controllers;

use App\Models\QueryRequest;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResultDownloadController extends Controller
{
    /**
     * Stream the stored (already masked) NDJSON result as CSV without loading
     * the whole file into memory.
     */
    public function __invoke(Request $request, QueryRequest $queryRequest): StreamedResponse
    {
        abort_unless($request->user()->can('downloadResult', $queryRequest), 403);

        audit()->record('result.downloaded', request: $queryRequest);

        $filename = sprintf('queryproxy-request-%d.csv', $queryRequest->id);

        return response()->streamDownload(function () use ($queryRequest) {
            $out = fopen('php://output', 'w');

            // A missing file still yields the header row, as it always did.
            $stream = Storage::disk($queryRequest->result_disk)
                ->readStream($queryRequest->result_path) ?? fopen('php://memory', 'r');

            Csv::writeFromNdjson($stream, $out, $queryRequest->result_columns ?? []);

            fclose($stream);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
