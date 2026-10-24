<?php

namespace App\Services\Doctor;

final class CheckResult
{
    public function __construct(
        public readonly string $check,
        public readonly CheckStatus $status,
        public readonly string $message,
    ) {}

    /** @return array{check: string, status: string, message: string} */
    public function toArray(): array
    {
        return ['check' => $this->check, 'status' => $this->status->value, 'message' => $this->message];
    }
}
