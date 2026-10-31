<?php

namespace App\Livewire\Concerns;

use App\Services\Connections\GrantService;

/**
 * The period picker shared by the access request form and the DBA's grant
 * form: a few presets plus a custom amount with a unit. The service owns the
 * allowed range (ADR-025); this only turns the form fields into minutes.
 */
trait ChoosesGrantDuration
{
    /** @var array<int, string> minutes => label */
    public const DURATION_PRESETS = [
        60 => '1 hour',
        240 => '4 hours',
        1440 => '1 day',
        10080 => '7 days',
    ];

    /** @var array<string, int> unit => minutes */
    public const DURATION_UNITS = [
        'minutes' => 1,
        'hours' => 60,
        'days' => 1440,
    ];

    /**
     * Minutes for the chosen preset, or for the custom amount when the preset
     * is "custom". Returns null after adding an error under $errorKey.
     */
    protected function chosenMinutes(string $preset, string $amount, string $unit, string $errorKey): ?int
    {
        if ($preset !== 'custom') {
            if (! array_key_exists((int) $preset, self::DURATION_PRESETS) || (string) (int) $preset !== $preset) {
                $this->addError($errorKey, 'Choose how long the access should last.');

                return null;
            }

            return (int) $preset;
        }

        $amount = trim($amount);

        if (! ctype_digit($amount) || (int) $amount < 1 || ! array_key_exists($unit, self::DURATION_UNITS)) {
            $this->addError($errorKey, 'Enter a whole number for the custom period and choose its unit.');

            return null;
        }

        $minutes = (int) $amount * self::DURATION_UNITS[$unit];

        if ($minutes > GrantService::MAX_DURATION_MINUTES) {
            $this->addError($errorKey, sprintf('The period may be at most %d days.', intdiv(GrantService::MAX_DURATION_MINUTES, 1440)));

            return null;
        }

        return $minutes;
    }
}
