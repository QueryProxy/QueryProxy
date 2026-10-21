<?php

namespace Tests\Support;

/**
 * A small JSON Schema checker for docs/audit-event.schema.json: just the
 * keywords that file uses (type, const, enum, pattern, minimum, required,
 * properties, additionalProperties, oneOf). It exists because no schema
 * library is installed; an unknown keyword fails loudly instead of being
 * ignored, so the schema cannot grow past what the test checks.
 */
class JsonSchema
{
    private const ANNOTATIONS = ['$schema', 'title', 'description'];

    private const KEYWORDS = ['type', 'const', 'enum', 'pattern', 'minimum', 'required', 'properties', 'additionalProperties', 'oneOf'];

    /** @return array<string, mixed> */
    public static function load(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> the violations; empty when the value conforms
     */
    public static function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];

        foreach (array_keys($schema) as $keyword) {
            if (! in_array($keyword, [...self::ANNOTATIONS, ...self::KEYWORDS], true)) {
                throw new \LogicException("Unsupported JSON Schema keyword \"{$keyword}\" at {$path}.");
            }
        }

        if (isset($schema['type']) && ! self::matchesType($value, (array) $schema['type'])) {
            return ["{$path}: expected ".implode('|', (array) $schema['type']).', got '.get_debug_type($value)];
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = "{$path}: must equal ".json_encode($schema['const']);
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: ".json_encode($value).' is not one of the allowed values';
        }

        if (isset($schema['pattern']) && is_string($value) && preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/', $value) !== 1) {
            $errors[] = "{$path}: does not match {$schema['pattern']}";
        }

        if (isset($schema['minimum']) && is_int($value) && $value < $schema['minimum']) {
            $errors[] = "{$path}: below the minimum {$schema['minimum']}";
        }

        if (isset($schema['oneOf'])) {
            $matching = 0;

            foreach ($schema['oneOf'] as $branch) {
                if (self::validate($value, $branch, $path) === []) {
                    $matching++;
                }
            }

            if ($matching !== 1) {
                $errors[] = "{$path}: must match exactly one alternative, matched {$matching}";
            }
        }

        if (is_array($value) && ! array_is_list($value) || ($value === [] && in_array('object', (array) ($schema['type'] ?? []), true))) {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $errors[] = "{$path}: missing required \"{$key}\"";
                }
            }

            foreach ($value as $key => $item) {
                if (isset($schema['properties'][$key])) {
                    $errors = [...$errors, ...self::validate($item, $schema['properties'][$key], "{$path}.{$key}")];
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}: unexpected property \"{$key}\"";
                }
            }
        }

        return $errors;
    }

    /** @param list<string> $types */
    private static function matchesType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                'array' => is_array($value) && array_is_list($value),
                default => throw new \LogicException("Unsupported JSON Schema type \"{$type}\"."),
            };

            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
