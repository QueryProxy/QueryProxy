<?php

namespace App\Http\Controllers;

use App\Models\QueryRequest;
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

            fputcsv($out, $queryRequest->result_columns ?? []);

            $stream = Storage::disk($queryRequest->result_disk)
                ->readStream($queryRequest->result_path);

            if ($stream !== null) {
                while (($line = fgets($stream)) !== false) {
                    $row = json_decode($line, true);

                    if (is_array($row)) {
                        fputcsv($out, array_map(
                            fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v),
                            $row,
                        ));
                    }
                }

                fclose($stream);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
