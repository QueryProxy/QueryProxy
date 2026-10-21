<?php

namespace App\Models;

use App\Enums\MaskMatchType;
use App\Enums\MaskStrategy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'team_id', 'connection_id', 'name', 'match_type', 'pattern', 'strategy', 'enabled',
])]
class MaskingRule extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'match_type' => MaskMatchType::class,
            'strategy' => MaskStrategy::class,
            'enabled' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function scopeForTeam(Builder $query, Team $team): Builder
    {
        return $query->where('team_id', $team->id);
    }

    /**
     * The default rule set.
     *
     * Installed automatically for every team created from now on (Team's
     * `created` hook calls Team::installDefaultMaskingRules()). Teams that
     * already existed are not backfilled; a DBA adds the set from the masking
     * page ("Add default rules"), which runs the same idempotent install.
     *
     * Column rules only ever see the *output* column name of the result set,
     * which the query author controls: `SELECT api_key AS k` defeats every
     * `*api_key*` pattern. The secret-shaped content rules below are the
     * backstop for exactly that — they match on the value, so an aliased (or
     * expression-wrapped) secret is still caught. They are deliberately
     * narrow: this set is installed for every new team, and an over-broad pattern
     * would mask ordinary rows and make results useless.
     *
     * @return list<array{name: string, match_type: string, pattern: string, strategy: string, enabled?: bool}>
     */
    public static function defaults(): array
    {
        return [
            ['name' => 'Email addresses (column)', 'match_type' => 'column', 'pattern' => '*email*', 'strategy' => 'partial'],
            ['name' => 'Phone numbers (column)', 'match_type' => 'column', 'pattern' => '*phone*', 'strategy' => 'partial'],
            ['name' => 'Credit cards (column)', 'match_type' => 'column', 'pattern' => '*card*', 'strategy' => 'partial'],
            ['name' => 'Secrets & tokens (column)', 'match_type' => 'column', 'pattern' => '*password*|*secret*|*token*|*api_key*', 'strategy' => 'full'],
            ['name' => 'Email addresses (content)', 'match_type' => 'regex', 'pattern' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', 'strategy' => 'partial'],
            // Card numbers: the candidate regex (real issuer identification
            // numbers, not a bare 13-16 digit run — see MaskValidator::Luhn)
            // is only a pre-filter; the Luhn checksum removes the false
            // positives, so an order number that merely looks like a card
            // stays readable.
            ['name' => 'Credit card numbers (content, Luhn)', 'match_type' => 'validator', 'pattern' => 'luhn', 'strategy' => 'partial'],

            // Vendor-issued credentials that announce themselves with a fixed
            // prefix: OpenAI (sk-), Slack bot/user/app (xox?-, xapp-), GitHub
            // PATs and app tokens (ghp_/gho_/ghu_/ghs_/ghr_, github_pat_),
            // GitLab (glpat-), Shopify (shpat_), AWS access key ids
            // (AKIA/ASIA) and Google API keys (AIza). The prefix plus a length
            // floor is what keeps this from firing on prose.
            ['name' => 'Vendor API keys & tokens (content)', 'match_type' => 'regex', 'pattern' => '/\b(?:sk-[A-Za-z0-9_-]{16,}|xox[abposr]-[A-Za-z0-9-]{10,}|xapp-[A-Za-z0-9-]{10,}|gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|glpat-[A-Za-z0-9_-]{16,}|shpat_[A-Za-z0-9]{20,}|A(?:KIA|SIA)[0-9A-Z]{16}|AIza[A-Za-z0-9_-]{30,})/', 'strategy' => 'full'],

            // JWTs: three base64url segments, the first always starting with
            // `eyJ` (the encoded `{"`). Bearer tokens and session cookies are
            // the usual shape, and the header prefix makes false hits unlikely.
            ['name' => 'JSON Web Tokens (content)', 'match_type' => 'regex', 'pattern' => '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/', 'strategy' => 'full'],

            // PEM private key material (RSA/EC/OPENSSH/PKCS#8). Matching the
            // armor header alone is enough to mask the whole cell.
            ['name' => 'Private key blocks (content)', 'match_type' => 'regex', 'pattern' => '/-----BEGIN (?:[A-Z0-9 ]+ )?PRIVATE KEY-----/', 'strategy' => 'full'],

            // Catch-all for prefix-less keys. Anchored to the *whole* value, so
            // it never fires on a long word inside a sentence, and it demands
            // 32+ chars containing lower, upper and digit at once. That mixed
            // case requirement is what excludes the common look-alikes: UUIDs
            // and hex digests (lower-case only), ULIDs and enum-ish codes
            // (upper-case only), slugs and identifiers (no case mix at 32+).
            ['name' => 'High-entropy secrets (content)', 'match_type' => 'regex', 'pattern' => '/\A(?=[A-Za-z0-9_-]*[a-z])(?=[A-Za-z0-9_-]*[A-Z])(?=[A-Za-z0-9_-]*[0-9])[A-Za-z0-9_-]{32,}\z/', 'strategy' => 'full'],

            ...self::turkeyDefaults(),
        ];
    }

    /**
     * Turkey PII pack: national id (TCKN), tax id (VKN), TR IBAN, mobile and
     * international phone numbers, vehicle plates. Part of defaults(), and
     * also installable on its own for teams that already exist ("Add TR
     * rules" on the masking page).
     *
     * Identifiers are masked with `hmac` so the same person still joins and
     * groups across result sets. Checksum validators keep ordinary numbers
     * readable; the VKN content validator ships disabled because roughly one
     * in ten random 10-digit numbers passes its checksum, which would mask
     * epoch timestamps and ids — the `*vkn*` / `*tax_no*` column rule covers
     * the common case and a DBA can enable the validator deliberately. This
     * "ships disabled" default is a product decision (user, 2026-10-20).
     *
     * @return list<array{name: string, match_type: string, pattern: string, strategy: string, enabled?: bool}>
     */
    public static function turkeyDefaults(): array
    {
        return [
            ['name' => 'TR national id (column)', 'match_type' => 'column', 'pattern' => '*tckn*|*tc_kimlik*|*national_id*', 'strategy' => 'hmac'],
            ['name' => 'IBAN (column)', 'match_type' => 'column', 'pattern' => '*iban*', 'strategy' => 'hmac'],
            ['name' => 'TR tax id (column)', 'match_type' => 'column', 'pattern' => '*vkn*|*tax_no*', 'strategy' => 'hmac'],
            ['name' => 'Vehicle plate (column)', 'match_type' => 'column', 'pattern' => '*plate*', 'strategy' => 'partial'],

            ['name' => 'TR national id (content, checksum)', 'match_type' => 'validator', 'pattern' => 'tckn', 'strategy' => 'hmac'],
            ['name' => 'TR IBAN (content, checksum)', 'match_type' => 'validator', 'pattern' => 'iban_tr', 'strategy' => 'hmac'],
            // Disabled on purpose: the VKN check digit is derived from the first
            // nine digits, so 1 in 10 random 10-digit numbers is "valid" and an
            // active content rule would mask ids and epoch timestamps.
            ['name' => 'TR tax id (content, checksum)', 'match_type' => 'validator', 'pattern' => 'vkn', 'strategy' => 'hmac', 'enabled' => false],

            // Token boundaries exclude word characters and `-` (the digit run
            // inside a GUID, `...-A5382587265D`, is not a phone number) and the
            // fraction of a dotted decimal (`3.5123456789`). `,` stays a
            // separator on purpose: GROUP_CONCAT lists numbers as `a,b` and
            // each one must still be masked (fail-closed).
            ['name' => 'TR mobile phone (content)', 'match_type' => 'regex', 'pattern' => '/(?<![\w-])(?<!\d\.)(?:\+90|0)?\s?5\d{2}[\s-]?\d{3}[\s-]?\d{2}[\s-]?\d{2}(?![\w-]|\.\d)/', 'strategy' => 'partial'],
            ['name' => 'International phone, E.164 (content)', 'match_type' => 'regex', 'pattern' => '/(?<![\w+])\+[1-9]\d{7,14}\b/', 'strategy' => 'partial'],
            // Province code 01-81 followed by 1-3 letters and 2-4 digits; upper
            // case only, so ordinary lower-case words never match. `-` and `_`
            // count as part of a token: without that, the first segment of an
            // upper-case GUID (SQL Server prints them that way) such as
            // `44FA2749-...` looks like a plate and about 1% of ids get masked.
            ['name' => 'TR vehicle plate (content)', 'match_type' => 'regex', 'pattern' => '/(?<![A-Za-z0-9_-])(?:0[1-9]|[1-7]\d|8[01])\s?(?:[A-Z]\s?\d{4}|[A-Z]{2}\s?\d{3,4}|[A-Z]{3}\s?\d{2,3})(?![A-Za-z0-9_-])/', 'strategy' => 'partial'],
        ];
    }
}
