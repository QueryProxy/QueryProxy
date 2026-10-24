<?php

namespace App\Services\Doctor\Checks;

use App\Models\Connection;
use App\Services\Doctor\CheckResult;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use Throwable;

/**
 * APP_KEY exists, has the length the cipher needs, and still decrypts what is
 * stored: connection credentials are encrypted with it (ADR-016), so a key that
 * changed without APP_PREVIOUS_KEYS makes every connection unusable.
 */
class AppKeyCheck extends BaseCheck
{
    public function name(): string
    {
        return 'app_key';
    }

    public function run(): CheckResult
    {
        $key = (string) config('app.key');

        if ($key === '') {
            return $this->fail('APP_KEY is not set; encrypted data cannot be read or written.');
        }

        $raw = Str::startsWith($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if ($raw === false || ! Encrypter::supported($raw, (string) config('app.cipher'))) {
            return $this->fail('APP_KEY is not a valid key for the '.config('app.cipher').' cipher (generate one with: php artisan key:generate --show).');
        }

        try {
            $sample = Connection::query()->whereNotNull('password')->oldest('id')->first();

            if ($sample !== null) {
                // Reading the attribute decrypts it.
                $sample->getAttribute('password');
            }
        } catch (DecryptException) {
            return $this->fail('APP_KEY is valid but cannot decrypt stored connection credentials; the key changed. Restore the old key or list it in APP_PREVIOUS_KEYS.');
        } catch (Throwable) {
            // Database problems belong to the database check.
        }

        return $this->ok('APP_KEY is set and valid.');
    }
}
