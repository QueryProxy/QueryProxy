<?php

use App\Services\Audit\AuditRecorder;

if (! function_exists('audit')) {
    function audit(): AuditRecorder
    {
        return app(AuditRecorder::class);
    }
}
