<?php

namespace App\Http\Controllers;

use App\Models\Connection;
use App\Services\Masking\ErrorMessageSanitizer;
use App\Services\Schema\SchemaBusyException;
use App\Services\Schema\SchemaReader;
use App\Services\Schema\SchemaTargetException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * JSON endpoint of the Studio's schema tree (ADR-029). It takes no SQL: the
 * only input is the connection and an optional `refresh` flag.
 */
class SchemaController extends Controller
{
    public function __invoke(Request $request, Connection $connection, SchemaReader $reader, ErrorMessageSanitizer $errors): JsonResponse
    {
        $user = $request->user();

        // The Studio works on the current team's connections only.
        abort_unless($connection->team_id === $user->currentTeam()?->id, 404);

        // Same right as submitting a query through the connection: a DBA of the
        // team, or a developer with a grant. Everyone else gets 403.
        Gate::forUser($user)->authorize('use', $connection);

        $refresh = $request->boolean('refresh');

        if ($refresh) {
            $key = sprintf('schema-refresh:%d:%d', $user->id, $connection->id);
            $limit = max(1, (int) config('queryproxy.schema_refresh_per_minute', 3));

            if (RateLimiter::tooManyAttempts($key, $limit)) {
                return $this->error('Too many refreshes. Try again later.', 429, RateLimiter::availableIn($key));
            }

            RateLimiter::hit($key, 60);
        }

        try {
            $schema = $reader->read($connection, $user, $refresh);
        } catch (SchemaBusyException $e) {
            return $this->error($e->getMessage(), 503, $e->retryAfter);
        } catch (SchemaTargetException $e) {
            Log::warning('Reading the schema of a connection failed.', [
                'connection_id' => $connection->id,
                'exception' => ($e->getPrevious() ?? $e)::class,
                'message' => $e->getMessage(),
            ]);

            return $this->error('The schema could not be read: '.$errors->sanitize($e, $connection), 502);
        } catch (Throwable $e) {
            // The application's own failure (audit log, cache): its message can
            // hold SQL with values, so only a fixed text goes to the user.
            Log::error('Serving the schema of a connection failed.', [
                'connection_id' => $connection->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->error('The schema could not be read.', 500);
        }

        return response()->json(['data' => $schema])->header('Cache-Control', 'no-store, private');
    }

    private function error(string $message, int $status, ?int $retryAfter = null): JsonResponse
    {
        $response = response()->json(['message' => $message], $status)->header('Cache-Control', 'no-store, private');

        return $retryAfter === null ? $response : $response->header('Retry-After', (string) $retryAfter);
    }
}
