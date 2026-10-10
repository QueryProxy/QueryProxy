<?php

namespace App\Services\Masking;

use App\Models\Connection;
use App\Models\MaskingRule;
use App\Services\Connections\DynamicConnectionFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single path every stored or audited query error message goes through.
 *
 * Driver errors echo row values (`Duplicate entry 'bob@x.io'`, `Key (phone)=(...)`,
 * the SQL with its bindings), so a failed query's message would otherwise leak
 * exactly what the result store masks. The message is first stripped of the
 * connection's own coordinates (host, user, password, database), then masked
 * with the connection's rules like result data (ADR-014). The raw message
 * belongs in the application log only.
 */
class ErrorMessageSanitizer
{
    /** Stored when the real message cannot be made safe, or has no connection to mask it with. */
    public const FALLBACK_MESSAGE = 'Job failed; details in application log.';

    public function __construct(
        private DynamicConnectionFactory $factory,
        private Masker $masker,
    ) {}

    /**
     * Fail-closed: any failure while redacting or masking — a deleted
     * connection, unreadable rules, PCRE trouble — yields the fixed message
     * instead of the raw text.
     *
     * @param  Collection<int, MaskingRule>|null  $rules  Pre-resolved rules for the connection; resolved here when null.
     */
    public function sanitize(Throwable|string $error, ?Connection $connection, ?Collection $rules = null): string
    {
        if ($connection === null) {
            return self::FALLBACK_MESSAGE;
        }

        try {
            $message = $error instanceof Throwable ? $error->getMessage() : $error;

            return $this->masker->maskText(
                (string) $this->factory->redactError($message, $connection),
                $rules ?? $this->masker->rulesFor($connection),
            );
        } catch (Throwable $e) {
            Log::error('Masking an error message failed; storing the fixed message instead.', [
                'exception' => $e::class,
            ]);

            return self::FALLBACK_MESSAGE;
        }
    }
}
