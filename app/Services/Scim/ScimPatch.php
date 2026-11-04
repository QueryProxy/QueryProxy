<?php

namespace App\Services\Scim;

/** Reads the Operations of a PATCH request (RFC 7644 section 3.5.2). */
final class ScimPatch
{
    public const SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    private const USER_URN = 'urn:ietf:params:scim:schemas:core:2.0:user:';

    private const GROUP_URN = 'urn:ietf:params:scim:schemas:core:2.0:group:';

    /**
     * @param  array<string, mixed>  $body
     * @return list<array{op: string, path: ?string, value: mixed}> op is add | remove | replace
     *
     * @throws ScimException
     */
    public static function operations(array $body): array
    {
        $operations = $body['Operations'] ?? $body['operations'] ?? null;

        if (! is_array($operations) || $operations === [] || ! array_is_list($operations)) {
            throw ScimException::invalidSyntax('A PATCH request needs a non-empty "Operations" list.');
        }

        $parsed = [];

        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                throw ScimException::invalidSyntax('Every operation must be an object.');
            }

            // Entra ID capitalises the verb ("Add", "Replace").
            $op = is_string($operation['op'] ?? null) ? strtolower(trim($operation['op'])) : '';

            if (! in_array($op, ['add', 'remove', 'replace'], true)) {
                throw ScimException::invalidSyntax('"op" must be add, remove or replace.');
            }

            $path = isset($operation['path']) && is_string($operation['path']) && trim($operation['path']) !== ''
                ? trim($operation['path'])
                : null;

            $parsed[] = ['op' => $op, 'path' => $path, 'value' => $operation['value'] ?? null];
        }

        return $parsed;
    }

    /** The path in lower case, without the core schema prefix. */
    public static function normalize(string $path): string
    {
        $path = strtolower(trim($path));

        foreach ([self::USER_URN, self::GROUP_URN] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return substr($path, strlen($prefix));
            }
        }

        return $path;
    }
}
