<?php

namespace App\Services\Audit\Sinks;

use RuntimeException;

/**
 * A sink could not deliver an event. Thrown without a previous exception on
 * purpose: `failed_jobs` stores the whole exception chain, and the transport
 * errors underneath (Guzzle, sockets) can carry the destination URL.
 */
class SinkDeliveryException extends RuntimeException {}
