<?php

namespace App\Enums;

use Closure;

/**
 * Checksum validators for `validator` masking rules.
 *
 * A regex cannot express a check digit, so a pattern such as "any 11 digits"
 * either misses identifiers or masks every 11-digit number. A validator finds
 * *candidates* with a narrow pattern and keeps only those whose checksum
 * holds, so a random digit run is left alone.
 *
 * The rule's `pattern` column carries the validator's value (`tckn`, ...).
 * Subjects are strings; numeric result cells are cast by the caller.
 */
enum MaskValidator: string
{
    /** Turkish national identity number (T.C. Kimlik No). */
    case Tckn = 'tckn';

    /** Turkish tax identification number (Vergi Kimlik No). */
    case Vkn = 'vkn';

    /** Turkish IBAN (TR + 24 digits, ISO 7064 mod 97-10). */
    case IbanTr = 'iban_tr';

    /** Payment card number: issuer-range pre-filter, then the Luhn check. */
    case Luhn = 'luhn';

    /**
     * Issuer identification ranges the Luhn validator pre-filters on, rather
     * than a bare 13-16 digit run: content rules see native int/float values,
     * and "any long digit run" would turn every 13-digit millisecond epoch or
     * snowflake id into a masked cell. Covered: Visa (4, 13 or 16 digits),
     * Mastercard (51-55 and 2221-2720), Amex (34/37), Discover (6011, 65,
     * 644-649), Diners (300-305, 36, 38), JCB (35). Space/hyphen grouping is
     * tolerated; the closing guard rejects a run that continues past the card
     * length, so a 17+ digit id never matches a 16-digit prefix.
     */
    private const CARD_CANDIDATE = '/\b(?:4(?:[ -]?\d){12}(?:(?:[ -]?\d){3})?|5[1-5](?:[ -]?\d){14}|(?:222[1-9]|22[3-9]\d|2[3-6]\d\d|27[01]\d|2720)(?:[ -]?\d){12}|3[47](?:[ -]?\d){13}|6011(?:[ -]?\d){12}|65(?:[ -]?\d){14}|64[4-9](?:[ -]?\d){13}|30[0-5](?:[ -]?\d){11}|3[68](?:[ -]?\d){12}|35(?:[ -]?\d){14})\b(?![ -]\d)/';

    public function label(): string
    {
        return match ($this) {
            self::Tckn => 'TCKN (Turkish national id)',
            self::Vkn => 'VKN (Turkish tax id)',
            self::IbanTr => 'IBAN (TR)',
            self::Luhn => 'Card number (Luhn)',
        };
    }

    /** PCRE that finds candidate substrings; {@see isValid()} decides. */
    public function candidatePattern(): string
    {
        return match ($this) {
            // Word-character boundaries, not just digits: a run of digits glued
            // to hex letters is the tail of a GUID (`...-F36882284146`), and about
            // 1 in 100 of those 11-digit runs would pass the TCKN checksum.
            self::Tckn => '/(?<![A-Za-z0-9_])\d{11}(?![A-Za-z0-9_])/',
            self::Vkn => '/(?<![A-Za-z0-9_])\d{10}(?![A-Za-z0-9_])/',
            self::IbanTr => '/(?<![A-Za-z0-9])TR\d{2}(?: ?\d){22}(?![A-Za-z0-9])/i',
            self::Luhn => self::CARD_CANDIDATE,
        };
    }

    public function isValid(string $candidate): bool
    {
        return match ($this) {
            self::Tckn => self::validTckn($candidate),
            self::Vkn => self::validVkn($candidate),
            self::IbanTr => self::validIbanTr($candidate),
            self::Luhn => self::validLuhn($candidate),
        };
    }

    /**
     * Whether the subject holds at least one candidate with a valid checksum.
     * Fail-closed: when PCRE cannot evaluate the subject the answer is true,
     * as for a regex rule.
     */
    public function matches(string $subject): bool
    {
        $found = @preg_match_all($this->candidatePattern(), $subject, $candidates);

        if ($found === false) {
            return true;
        }

        foreach ($candidates[0] as $candidate) {
            if ($this->isValid($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace every valid candidate in the text with `$mask(candidate)`.
     * Returns null when PCRE fails, so the caller can fail closed.
     *
     * @param  Closure(string): string  $mask
     */
    public function replaceIn(string $text, Closure $mask): ?string
    {
        return @preg_replace_callback(
            $this->candidatePattern(),
            fn (array $match): string => $this->isValid($match[0]) ? $mask($match[0]) : $match[0],
            $text,
        );
    }

    private static function validTckn(string $value): bool
    {
        if (preg_match('/\A[1-9]\d{10}\z/', $value) !== 1) {
            return false;
        }

        $d = array_map('intval', str_split($value));

        $tenth = (($d[0] + $d[2] + $d[4] + $d[6] + $d[8]) * 7 - ($d[1] + $d[3] + $d[5] + $d[7])) % 10;

        if (($tenth + 10) % 10 !== $d[9]) {
            return false;
        }

        return array_sum(array_slice($d, 0, 10)) % 10 === $d[10];
    }

    private static function validVkn(string $value): bool
    {
        if (preg_match('/\A\d{10}\z/', $value) !== 1) {
            return false;
        }

        $d = array_map('intval', str_split($value));
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $tmp = ($d[$i] + (9 - $i)) % 10;
            $part = ($tmp * (2 ** (9 - $i))) % 9;

            if ($tmp !== 0 && $part === 0) {
                $part = 9;
            }

            $sum += $part;
        }

        return (10 - $sum % 10) % 10 === $d[9];
    }

    private static function validIbanTr(string $value): bool
    {
        $iban = strtoupper(str_replace(' ', '', $value));

        if (preg_match('/\ATR\d{24}\z/', $iban) !== 1) {
            return false;
        }

        // Move the country code and check digits to the end; TR = 29 27.
        $digits = substr($iban, 4).'2927'.substr($iban, 2, 2);
        $remainder = 0;

        foreach (str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder === 1;
    }

    private static function validLuhn(string $value): bool
    {
        $digits = preg_replace('/\D/', '', $value);

        if ($digits === null || strlen($digits) < 13 || strlen($digits) > 19) {
            return false;
        }

        $sum = 0;

        foreach (array_reverse(str_split($digits)) as $i => $digit) {
            $n = (int) $digit;

            if ($i % 2 === 1) {
                $n *= 2;

                if ($n > 9) {
                    $n -= 9;
                }
            }

            $sum += $n;
        }

        return $sum % 10 === 0;
    }
}
