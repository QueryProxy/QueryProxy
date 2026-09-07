<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication: secret provisioning, QR rendering,
 * code verification and single-use recovery codes (stored as bcrypt hashes).
 */
class TwoFactorService
{
    public function __construct(private Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(config('app.name', 'QueryProxy'), $user->email, $secret);
    }

    public function qrCodeSvg(User $user, string $secret): string
    {
        $renderer = new ImageRenderer(new RendererStyle(192), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($this->otpauthUrl($user, $secret));
    }

    public function verify(string $secret, string $code): bool
    {
        $code = (string) preg_replace('/\D+/', '', $code);

        return $code !== '' && $this->engine->verifyKey($secret, $code) !== false;
    }

    /**
     * Generate 8 fresh single-use recovery codes; only their hashes persist.
     *
     * @return list<string>
     */
    public function issueRecoveryCodes(User $user): array
    {
        $codes = [];

        for ($i = 0; $i < 8; $i++) {
            $codes[] = Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5));
        }

        $user->forceFill([
            'two_factor_recovery_codes' => array_map(fn (string $code) => Hash::make($code), $codes),
        ])->save();

        return $codes;
    }

    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->two_factor_recovery_codes ?? [];

        foreach ($hashes as $index => $hash) {
            if (Hash::check(trim($code), $hash)) {
                unset($hashes[$index]);

                $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

                return true;
            }
        }

        return false;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /** Does the instance-wide 2FA requirement apply to this user? */
    public function isRequiredFor(User $user): bool
    {
        return match (config('queryproxy.require_two_factor', 'none')) {
            'all' => true,
            'admins' => $user->isAdmin(),
            'dba' => $user->isAdmin() || $user->isDbaAnywhere(),
            default => false,
        };
    }
}
