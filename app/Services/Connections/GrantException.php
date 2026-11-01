<?php

namespace App\Services\Connections;

use RuntimeException;

/** A grant operation was refused for a reason the caller can show to the user. */
class GrantException extends RuntimeException {}
