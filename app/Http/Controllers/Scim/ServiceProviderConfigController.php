<?php

namespace App\Http\Controllers\Scim;

use App\Services\Scim\ScimException;
use App\Services\Scim\ScimGroupService;
use App\Services\Scim\ScimUserService;
use Illuminate\Http\JsonResponse;

/** ServiceProviderConfig, Schemas and ResourceTypes: what this SCIM server supports (RFC 7643 section 5 to 7). */
class ServiceProviderConfigController extends ScimController
{
    public function serviceProviderConfig(): JsonResponse
    {
        return $this->respond([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'documentationUri' => null,
            'patch' => ['supported' => true],
            'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => ScimUserService::MAX_PAGE],
            'changePassword' => ['supported' => false],
            'sort' => ['supported' => false],
            'etag' => ['supported' => false],
            'authenticationSchemes' => [[
                'type' => 'oauthbearertoken',
                'name' => 'Bearer token',
                'description' => 'A SCIM token created with the queryproxy:scim-token command, sent as Authorization: Bearer.',
                'primary' => true,
            ]],
            'meta' => [
                'resourceType' => 'ServiceProviderConfig',
                'location' => route('scim.service-provider-config'),
            ],
        ]);
    }

    public function schemas(): JsonResponse
    {
        $schemas = array_values($this->schemaDefinitions());

        return $this->respond($this->list($schemas));
    }

    public function schema(string $id): JsonResponse
    {
        return $this->respond($this->schemaDefinitions()[$id] ?? throw ScimException::notFound("Schema {$id} not found."));
    }

    public function resourceTypes(): JsonResponse
    {
        return $this->respond($this->list(array_values($this->resourceTypeDefinitions())));
    }

    public function resourceType(string $id): JsonResponse
    {
        return $this->respond($this->resourceTypeDefinitions()[$id] ?? throw ScimException::notFound("Resource type {$id} not found."));
    }

    /**
     * @param  list<array<string, mixed>>  $resources
     * @return array<string, mixed>
     */
    private function list(array $resources): array
    {
        return [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => count($resources),
            'startIndex' => 1,
            'itemsPerPage' => count($resources),
            'Resources' => $resources,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function resourceTypeDefinitions(): array
    {
        return [
            'User' => [
                'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
                'id' => 'User',
                'name' => 'User',
                'endpoint' => '/Users',
                'description' => 'A QueryProxy user. Users the provider did not create and that do not sign in through SSO are not visible.',
                'schema' => ScimUserService::SCHEMA,
                'meta' => ['resourceType' => 'ResourceType', 'location' => route('scim.resource-types.show', ['id' => 'User'])],
            ],
            'Group' => [
                'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
                'id' => 'Group',
                'name' => 'Group',
                'endpoint' => '/Groups',
                'description' => 'An identity provider group. Its display name is matched against QUERYPROXY_OIDC_ROLE_MAP.',
                'schema' => ScimGroupService::SCHEMA,
                'meta' => ['resourceType' => 'ResourceType', 'location' => route('scim.resource-types.show', ['id' => 'Group'])],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function schemaDefinitions(): array
    {
        $string = fn (string $name, string $description, array $extra = []): array => $extra + [
            'name' => $name,
            'type' => 'string',
            'multiValued' => false,
            'description' => $description,
            'required' => false,
            'caseExact' => false,
            'mutability' => 'readWrite',
            'returned' => 'default',
            'uniqueness' => 'none',
        ];

        $meta = fn (string $id, string $name, string $description, array $attributes): array => [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Schema'],
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'attributes' => $attributes,
            'meta' => ['resourceType' => 'Schema', 'location' => route('scim.schemas.show', ['id' => $id])],
        ];

        return [
            ScimUserService::SCHEMA => $meta(ScimUserService::SCHEMA, 'User', 'A QueryProxy user.', [
                $string('userName', 'Unique identifier for the user, usually the e-mail address.', ['required' => true, 'uniqueness' => 'server']),
                $string('externalId', 'Identifier of the user at the identity provider.'),
                $string('displayName', 'Name of the user.'),
                [
                    'name' => 'name',
                    'type' => 'complex',
                    'multiValued' => false,
                    'description' => 'The name of the user. Only formatted is kept; givenName and familyName are combined into it.',
                    'required' => false,
                    'mutability' => 'readWrite',
                    'returned' => 'default',
                    'uniqueness' => 'none',
                    'subAttributes' => [
                        $string('formatted', 'The full name.'),
                        $string('givenName', 'The given name (write only; combined into formatted).'),
                        $string('familyName', 'The family name (write only; combined into formatted).'),
                    ],
                ],
                [
                    'name' => 'emails',
                    'type' => 'complex',
                    'multiValued' => true,
                    'description' => 'E-mail address. One is kept: the primary one, which QueryProxy uses to sign the user in.',
                    'required' => false,
                    'mutability' => 'readWrite',
                    'returned' => 'default',
                    'uniqueness' => 'none',
                    'subAttributes' => [
                        $string('value', 'The address.'),
                        $string('type', 'work'),
                        ['name' => 'primary', 'type' => 'boolean', 'multiValued' => false, 'description' => 'Primary address.', 'required' => false, 'mutability' => 'readWrite', 'returned' => 'default'],
                    ],
                ],
                ['name' => 'active', 'type' => 'boolean', 'multiValued' => false, 'description' => 'false suspends the user (they are never deleted).', 'required' => false, 'mutability' => 'readWrite', 'returned' => 'default'],
            ]),
            ScimGroupService::SCHEMA => $meta(ScimGroupService::SCHEMA, 'Group', 'A group of users.', [
                $string('displayName', 'Name of the group; matched against the OIDC role map.', ['required' => true]),
                $string('externalId', 'Identifier of the group at the identity provider.'),
                [
                    'name' => 'members',
                    'type' => 'complex',
                    'multiValued' => true,
                    'description' => 'Members of the group.',
                    'required' => false,
                    'mutability' => 'readWrite',
                    'returned' => 'default',
                    'uniqueness' => 'none',
                    'subAttributes' => [
                        $string('value', 'Id of the user.', ['mutability' => 'immutable']),
                        $string('display', 'Name of the user.', ['mutability' => 'readOnly']),
                    ],
                ],
            ]),
        ];
    }
}
