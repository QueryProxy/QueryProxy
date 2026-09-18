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
     * Sensible defaults every team starts from.
     *
     * Column rules only ever see the *output* column name of the result set,
     * which the query author controls: `SELECT api_key AS k` defeats every
     * `*api_key*` pattern. The secret-shaped content rules below are the
     * backstop for exactly that — they match on the value, so an aliased (or
     * expression-wrapped) secret is still caught. They are deliberately
     * narrow: this set is applied to every new team, and an over-broad pattern
     * would mask ordinary rows and make results useless.
     *
     * @return list<array{name: string, match_type: string, pattern: string, strategy: string}>
     */
    public static function defaults(): array
    {
        return [
            ['name' => 'Email addresses (column)', 'match_type' => 'column', 'pattern' => '*email*', 'strategy' => 'partial'],
            ['name' => 'Phone numbers (column)', 'match_type' => 'column', 'pattern' => '*phone*', 'strategy' => 'partial'],
            ['name' => 'Credit cards (column)', 'match_type' => 'column', 'pattern' => '*card*', 'strategy' => 'partial'],
            ['name' => 'Secrets & tokens (column)', 'match_type' => 'column', 'pattern' => '*password*|*secret*|*token*|*api_key*', 'strategy' => 'full'],
            ['name' => 'Email addresses (content)', 'match_type' => 'regex', 'pattern' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', 'strategy' => 'partial'],
            // Anchored to real issuer identification numbers rather than to a
            // bare 13-16 digit run. Content rules see native int/float values
            // (PDO does not stringify BIGINT), and "any long digit run" turns
            // every 13-digit millisecond epoch, snowflake id and sequential
            // order number into a masked cell — noise that pushes operators to
            // switch masking off altogether. Networks covered: Visa (4, 13 or
            // 16 digits), Mastercard (51-55 and the 2221-2720 range),
            // Amex (34/37), Discover (6011, 65, 644-649), Diners (300-305, 36,
            // 38), JCB (35). Space/hyphen grouping stays tolerated; the closing
            // guard rejects a run that continues past the card length, so a
            // 17+ digit id can never match a 16-digit prefix. No Luhn check —
            // a regex cannot express one, and the IIN narrowing carries it.
            ['name' => 'Credit card numbers (content)', 'match_type' => 'regex', 'pattern' => '/\b(?:4(?:[ -]?\d){12}(?:(?:[ -]?\d){3})?|5[1-5](?:[ -]?\d){14}|(?:222[1-9]|22[3-9]\d|2[3-6]\d\d|27[01]\d|2720)(?:[ -]?\d){12}|3[47](?:[ -]?\d){13}|6011(?:[ -]?\d){12}|65(?:[ -]?\d){14}|64[4-9](?:[ -]?\d){13}|30[0-5](?:[ -]?\d){11}|3[68](?:[ -]?\d){12}|35(?:[ -]?\d){14})\b(?![ -]\d)/', 'strategy' => 'partial'],

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
        ];
    }
}
