<?php

namespace App\Services\Doctor;

/**
 * One read-only health or configuration check of queryproxy:doctor. A check
 * inspects an installation and reports; it never changes configuration, data
 * or files.
 */
interface Check
{
    /** Stable identifier, shown in the first column of the report. */
    public function name(): string;

    public function run(): CheckResult;
}
