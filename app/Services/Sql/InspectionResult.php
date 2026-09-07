<?php

namespace App\Services\Sql;

use App\Enums\StatementType;

final class InspectionResult
{
    /**
     * @param  list<StatementInfo>  $statements  executable statements (BEGIN/COMMIT stripped)
     * @param  list<string>  $violations
     */
    public function __construct(
        public readonly array $statements,
        public readonly bool $isTransaction,
        public readonly array $violations,
    ) {}

    public function passes(): bool
    {
        return $this->violations === [];
    }

    /** Overall request type: read only when every statement reads. */
    public function type(): StatementType
    {
        foreach ($this->statements as $statement) {
            if ($statement->type === StatementType::Write) {
                return StatementType::Write;
            }
        }

        return StatementType::Read;
    }

    /** True when any statement is DDL (CREATE / ALTER / DROP / TRUNCATE / RENAME). */
    public function hasDdl(): bool
    {
        foreach ($this->statements as $statement) {
            if ($statement->isDdl) {
                return true;
            }
        }

        return false;
    }

    public function preparedSql(): string
    {
        return implode(";\n", array_map(
            fn (StatementInfo $s) => $s->preparedSql,
            $this->statements,
        ));
    }
}
