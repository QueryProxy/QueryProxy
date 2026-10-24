<?php

namespace App\Services\Doctor\Checks;

use App\Services\Doctor\CheckResult;
use App\Support\SecretFile;

/** The NAME_FILE secrets (docker/secret-files.sh, App\Support\SecretFile) resolve cleanly. */
class SecretFilesCheck extends BaseCheck
{
    public function name(): string
    {
        return 'secret_files';
    }

    public function run(): CheckResult
    {
        $report = SecretFile::inspect();

        if ($report['problems'] !== []) {
            return $this->fail(implode(' ', $report['problems']));
        }

        return $report['in_use'] === []
            ? $this->ok('No *_FILE secret is set in this process.')
            : $this->ok('Read from files: '.implode(', ', $report['in_use']).'.');
    }
}
