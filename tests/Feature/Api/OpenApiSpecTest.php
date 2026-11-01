<?php

use App\Enums\QueryRequestStatus;
use App\Http\Resources\Api\V1\ConnectionResource;
use App\Http\Resources\Api\V1\QueryRequestResource;
use App\Services\Api\TokenService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\ApiWorld;

function openApiSpec(): array
{
    return Yaml::parseFile(base_path('docs/openapi.yaml'));
}

/** "POST /api/v1/requests/{}" for every operation of the spec. */
function specOperations(): array
{
    $operations = [];

    foreach (openApiSpec()['paths'] as $path => $item) {
        foreach (array_intersect_key($item, array_flip(['get', 'post', 'put', 'patch', 'delete'])) as $method => $operation) {
            $operations[strtoupper($method).' '.preg_replace('/\{[^}]+\}/', '{}', $path)] = $operation;
        }
    }

    return $operations;
}

/** @return array<string, LaravelRoute> the same keys, for the /api/v1 routes */
function apiRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes[$method.' '.preg_replace('/\{[^}]+\}/', '{}', '/'.$route->uri())] = $route;
        }
    }

    return $routes;
}

/** @return array{all: list<string>, any: list<string>} */
function routeScopes(LaravelRoute $route): array
{
    $scopes = ['all' => [], 'any' => []];

    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware)) {
            continue;
        }

        if (str_starts_with($middleware, 'abilities:')) {
            $scopes['all'] = explode(',', substr($middleware, strlen('abilities:')));
        } elseif (str_starts_with($middleware, 'ability:')) {
            $scopes['any'] = explode(',', substr($middleware, strlen('ability:')));
        }
    }

    return $scopes;
}

test('the specification is OpenAPI 3.1 and has the bearer scheme', function () {
    $spec = openApiSpec();

    expect($spec['openapi'])->toStartWith('3.1.')
        ->and($spec['components']['securitySchemes']['bearerAuth'])->toMatchArray(['type' => 'http', 'scheme' => 'bearer'])
        ->and($spec['security'])->toBe([['bearerAuth' => []]]);
});

test('the specification describes exactly the /api/v1 routes', function () {
    expect(array_keys(specOperations()))->toEqualCanonicalizing(array_keys(apiRoutes()));
});

test('every operation requires the scopes its route enforces', function () {
    $routes = apiRoutes();

    foreach (specOperations() as $key => $operation) {
        $scopes = routeScopes($routes[$key]);

        expect($operation['x-scopes-all'] ?? [])->toEqualCanonicalizing($scopes['all'], $key)
            ->and($operation['x-scopes-any'] ?? [])->toEqualCanonicalizing($scopes['any'], $key)
            ->and(array_merge($scopes['all'], $scopes['any']))->not->toBe([], "$key has no scope middleware");
    }
});

test('the scopes in the specification are exactly the scopes a token can carry', function () {
    $used = [];

    foreach (specOperations() as $operation) {
        $used = array_merge($used, $operation['x-scopes-all'] ?? [], $operation['x-scopes-any'] ?? []);
    }

    expect(array_values(array_unique($used)))->toEqualCanonicalizing(TokenService::scopes());
});

test('every operation documents the authentication, authorization and rate limit answers', function () {
    foreach (specOperations() as $key => $operation) {
        // YAML turns the numeric response codes into integer keys.
        expect(array_map('strval', array_keys($operation['responses'])))->toContain('401', '403', '429');
        expect($operation)->toHaveKey('operationId');
    }
});

test('the request schema lists exactly the fields the resource returns', function () {
    $world = ApiWorld::make();
    $request = $world->pendingRequest($world->developer);
    $http = Request::create('/api/v1/requests/'.$request->id);
    $http->setUserResolver(fn () => $world->developer);

    $schema = openApiSpec()['components']['schemas']['QueryRequest'];
    $returned = array_keys((new QueryRequestResource($request))->toArray($http));

    expect($returned)->toEqualCanonicalizing(array_keys($schema['properties']))
        ->and($schema['required'])->toEqualCanonicalizing(array_keys($schema['properties']));

    $result = (new QueryRequestResource($request))->toArray($http)['result'];

    expect(array_keys($result))->toEqualCanonicalizing(array_keys($schema['properties']['result']['properties']));
});

test('the connection schema lists exactly the fields the resource returns', function () {
    $world = ApiWorld::make();
    $http = Request::create('/api/v1/connections');

    $schema = openApiSpec()['components']['schemas']['Connection'];

    expect(array_keys((new ConnectionResource($world->connection))->toArray($http)))->toEqualCanonicalizing(array_keys($schema['properties']))
        ->and($schema['required'])->toEqualCanonicalizing(array_keys($schema['properties']));
});

test('the submit schema accepts the fields the controller validates', function () {
    $schema = openApiSpec()['components']['schemas']['SubmitRequest'];

    expect(array_keys($schema['properties']))->toEqualCanonicalizing(['connection_id', 'sql', 'title', 'reason'])
        ->and($schema['required'])->toEqualCanonicalizing(['connection_id', 'sql']);
});

test('the status enum of the specification is the status enum of the application', function () {
    $enum = openApiSpec()['components']['schemas']['QueryRequest']['properties']['status']['enum'];

    expect($enum)->toEqualCanonicalizing(array_column(QueryRequestStatus::cases(), 'value'));
});
