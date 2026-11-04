<?php

namespace App\Services\Scim;

/**
 * The one filter form identity providers use to look a resource up before they
 * create it: `attribute eq "value"` (RFC 7644 section 3.4.2.2). Anything else
 * is refused with `invalidFilter` rather than answered with a wrong list.
 */
final class ScimFilter
{
    private function __construct(
        public readonly string $attribute,
        public readonly string $value,
    ) {}

    /**
     * @param  list<string>  $allowed  attribute names (lower case) the resource supports
     *
     * @throws ScimException
     */
    public static function parse(?string $filter, array $allowed): ?self
    {
        if ($filter === null || trim($filter) === '') {
            return null;
        }

        if (preg_match('/^\s*([A-Za-z][A-Za-z0-9_.:-]*)\s+eq\s+"((?:[^"\\\\]|\\\\.)*)"\s*$/i', $filter, $match) !== 1) {
            throw ScimException::invalidFilter('Only filters of the form: attribute eq "value" are supported.');
        }

        $attribute = strtolower($match[1]);

        if (! in_array($attribute, $allowed, true)) {
            throw ScimException::invalidFilter('Filtering on "'.$match[1].'" is not supported. Supported: '.implode(', ', $allowed).'.');
        }

        // RFC 7644 string escapes: only \" and \\ are taken; anything else stays literal.
        $value = strtr($match[2], ['\\"' => '"', '\\\\' => '\\']);

        if (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw ScimException::invalidFilter('The filter value is not valid text.');
        }

        return new self($attribute, $value);
    }
}
