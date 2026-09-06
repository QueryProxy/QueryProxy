<?php

namespace App\Enums;

enum DbDriver: string
{
    case Pgsql = 'pgsql';
    case Mysql = 'mysql';
    case Mariadb = 'mariadb';
    case Sqlite = 'sqlite';
    case Sqlsrv = 'sqlsrv';

    public function label(): string
    {
        return match ($this) {
            self::Pgsql => 'PostgreSQL',
            self::Mysql => 'MySQL',
            self::Mariadb => 'MariaDB',
            self::Sqlite => 'SQLite',
            self::Sqlsrv => 'SQL Server',
        };
    }

    public function defaultPort(): ?int
    {
        return match ($this) {
            self::Pgsql => 5432,
            self::Mysql, self::Mariadb => 3306,
            self::Sqlsrv => 1433,
            self::Sqlite => null,
        };
    }

    public function usesHost(): bool
    {
        return $this !== self::Sqlite;
    }
}
