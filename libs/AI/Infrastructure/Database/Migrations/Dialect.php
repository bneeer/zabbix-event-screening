<?php

declare(strict_types=1);

namespace AI\Infrastructure\Database\Migrations;

use AI\Infrastructure\Database\DatabaseConfig;

/**
 * Small set of SQL fragments that differ between the supported engines, so a
 * single migration file can target SQLite, MySQL and PostgreSQL.
 */
final readonly class Dialect
{
    public function __construct(public string $driver)
    {
    }

    public function primaryKey(): string
    {
        return match ($this->driver) {
            DatabaseConfig::DRIVER_SQLITE => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            DatabaseConfig::DRIVER_MYSQL => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            DatabaseConfig::DRIVER_PGSQL => 'BIGSERIAL PRIMARY KEY',
        };
    }

    public function string(int $length = 255): string
    {
        return "VARCHAR({$length})";
    }

    public function text(): string
    {
        return match ($this->driver) {
            DatabaseConfig::DRIVER_MYSQL => 'LONGTEXT',
            default => 'TEXT',
        };
    }

    public function json(): string
    {
        return match ($this->driver) {
            DatabaseConfig::DRIVER_PGSQL => 'JSONB',
            DatabaseConfig::DRIVER_MYSQL => 'JSON',
            default => 'TEXT',
        };
    }

    public function integer(): string
    {
        return 'INTEGER';
    }

    public function datetime(): string
    {
        return match ($this->driver) {
            DatabaseConfig::DRIVER_MYSQL => 'DATETIME',
            DatabaseConfig::DRIVER_PGSQL => 'TIMESTAMP',
            default => 'TEXT',
        };
    }

    public function now(): string
    {
        return match ($this->driver) {
            DatabaseConfig::DRIVER_SQLITE => "STRFTIME('%Y-%m-%d %H:%M:%S','now')",
            default => 'CURRENT_TIMESTAMP',
        };
    }

    public function tableOptions(): string
    {
        return $this->driver === DatabaseConfig::DRIVER_MYSQL
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : '';
    }

    public function quote(string $identifier): string
    {
        return $this->driver === DatabaseConfig::DRIVER_MYSQL ? "`{$identifier}`" : "\"{$identifier}\"";
    }

    /**
     * SQL that returns one row when the given table exists.
     */
    public function tableExistsQuery(): string
    {
        return match ($this->driver) {
            DatabaseConfig::DRIVER_SQLITE => "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name",
            DatabaseConfig::DRIVER_MYSQL => 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :name',
            DatabaseConfig::DRIVER_PGSQL => "SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :name",
        };
    }
}
