<?php

namespace App\Services\Secrets\Drivers;

use App\Services\Secrets\SecretReference;
use App\Services\Secrets\SecretResolutionException;

/** One secret store behind one reference scheme (ADR-028). */
interface SecretDriver
{
    /** The reference scheme this driver serves (SecretReference::VAULT, ...). */
    public function scheme(): string;

    /**
     * Fetch the value a reference points at.
     *
     * @throws SecretResolutionException on every failure; never anything else, and never with secret material in it
     */
    public function fetch(SecretReference $reference): string;

    /** Has the operator set this store up at all (any of its settings present)? */
    public function isConfigured(): bool;

    /**
     * Static checks of the configuration, without any network call. Used by
     * queryproxy:doctor.
     *
     * @return array{problems: list<string>, warnings: list<string>}
     */
    public function diagnose(): array;
}
