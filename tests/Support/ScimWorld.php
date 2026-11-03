<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Scim\ScimTokenService;
use Illuminate\Testing\TestResponse;

/**
 * Turns SCIM on, mints a SCIM token and calls /scim/v2 with it. Shared by
 * tests/Feature/Scim.
 */
class ScimWorld
{
    public const USER = 'urn:ietf:params:scim:schemas:core:2.0:User';

    public const GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    public const PATCH = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    public const ERROR = 'urn:ietf:params:scim:api:messages:2.0:Error';

    public string $token;

    /** Turns SCIM on and creates a token. */
    public static function make(): self
    {
        config(['queryproxy.scim.enabled' => true]);

        $world = new self;
        [, $world->token] = app(ScimTokenService::class)->create('test idp');

        return $world;
    }

    /** @param  array<string, mixed>|null  $body */
    public function call(string $method, string $uri, ?array $body = null, ?string $token = null): TestResponse
    {
        return test()->call(
            $method,
            '/scim/v2'.$uri,
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.($token ?? $this->token),
                'HTTP_ACCEPT' => 'application/scim+json',
                'CONTENT_TYPE' => 'application/scim+json',
            ],
            $body === null ? null : json_encode($body),
        );
    }

    /** A user the identity provider created through SCIM. */
    public static function scimUser(array $attributes = []): User
    {
        $user = User::factory()->create(['password' => null] + $attributes);
        $user->forceFill(['scim_provisioned_at' => now()])->save();

        return $user->refresh();
    }

    /** @return array<string, mixed> */
    public static function patch(array ...$operations): array
    {
        return ['schemas' => [self::PATCH], 'Operations' => $operations];
    }
}
