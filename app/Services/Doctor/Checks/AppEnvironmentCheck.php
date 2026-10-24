<?php

namespace App\Services\Doctor\Checks;

use App\Services\Doctor\CheckResult;

/** Debug mode leaks stack traces and configuration; it must be off in production. */
class AppEnvironmentCheck extends BaseCheck
{
    public function name(): string
    {
        return 'app_environment';
    }

    public function run(): CheckResult
    {
        $environment = (string) config('app.env');

        if ((bool) config('app.debug') && $environment === 'production') {
            return $this->fail('APP_DEBUG is on while APP_ENV=production; error pages expose stack traces and configuration.');
        }

        if ((bool) config('app.debug')) {
            return $this->warn("APP_DEBUG is on (APP_ENV={$environment}); fine for development, never in production.");
        }

        return $this->ok("APP_ENV={$environment}, APP_DEBUG off.");
    }
}
