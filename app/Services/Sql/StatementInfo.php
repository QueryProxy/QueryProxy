<?php

namespace App\Services\Sql;

use App\Enums\StatementType;

final class StatementInfo
{
    public function __construct(
        public readonly string $sql,
        public readonly string $preparedSql,
        public readonly StatementType $type,
        public readonly bool $parsed,
        public readonly bool $limitInjected = false,
        public readonly bool $limitClamped = false,
        public readonly bool $isDdl = false,
    ) {}
}
