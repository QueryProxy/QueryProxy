<?php

namespace App\Http\Controllers\Scim;

use App\Http\Controllers\Controller;
use App\Services\Scim\ScimException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Shared plumbing of the /scim/v2 controllers (ADR-026). */
abstract class ScimController extends Controller
{
    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function respond(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json($data, $status, $headers + ['Content-Type' => ScimException::CONTENT_TYPE]);
    }

    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204, ['Content-Type' => ScimException::CONTENT_TYPE]);
    }

    /**
     * The JSON object in the request body.
     *
     * @return array<string, mixed>
     *
     * @throws ScimException
     */
    protected function body(Request $request): array
    {
        $body = json_decode($request->getContent(), true);

        if (! is_array($body) || ($body !== [] && array_is_list($body))) {
            throw ScimException::invalidSyntax('The request body must be a JSON object.');
        }

        return $body;
    }

    protected function startIndex(Request $request): int
    {
        return max(1, (int) $request->query('startIndex', 1));
    }

    protected function count(Request $request): int
    {
        return max(0, (int) $request->query('count', 100));
    }
}
