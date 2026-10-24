<?php

namespace App\Services\Doctor;

/**
 * Marks a check that reads the application database. When the database cannot
 * be reached, Doctor skips it instead of repeating the same connection error
 * once per check; the `database` check carries the actual failure.
 */
interface NeedsDatabase extends Check {}
