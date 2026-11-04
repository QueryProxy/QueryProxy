<?php

namespace App\Services\Scim;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A SCIM error (RFC 7644 section 3.12). Thrown by the services and rendered
 * by itself as an `Error` message in application/scim+json.
 */
class ScimException extends RuntimeException
{
    public const ERROR_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:Error';

    public const CONTENT_TYPE = 'application/scim+json';

    /** @param  array<string, string>  $headers */
    public function __construct(
        public readonly int $status,
        string $detail,
        public readonly ?string $scimType = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($detail);
    }

    public static function notFound(string $detail = 'Resource not found.'): self
    {
        return new self(404, $detail);
    }

    public static function invalidValue(string $detail): self
    {
        return new self(400, $detail, 'invalidValue');
    }

    public static function invalidSyntax(string $detail): self
    {
        return new self(400, $detail, 'invalidSyntax');
    }

    public static function invalidFilter(string $detail): self
    {
        return new self(400, $detail, 'invalidFilter');
    }

    public static function uniqueness(string $detail): self
    {
        return new self(409, $detail, 'uniqueness');
    }

    /** @param  array<string, string>  $headers */
    public static function response(int $status, string $detail, ?string $scimType = null, array $headers = []): JsonResponse
    {
        $body = [
            'schemas' => [self::ERROR_SCHEMA],
            'status' => (string) $status,
            'detail' => $detail,
        ];

        if ($scimType !== null) {
            $body['scimType'] = $scimType;
        }

        return response()->json($body, $status, $headers + ['Content-Type' => self::CONTENT_TYPE]);
    }

    public function render(): JsonResponse
    {
        return self::response($this->status, $this->getMessage(), $this->scimType, $this->headers);
    }
}
