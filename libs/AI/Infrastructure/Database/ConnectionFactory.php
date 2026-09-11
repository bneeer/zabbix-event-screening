<?php

declare(strict_types=1);

namespace AI\Infrastructure\Database;

use PDO;

/**
 * Creates PDO connections and (optionally) the target database itself.
 */
final class ConnectionFactory
{
    public function __construct(private readonly DatabaseConfig $config)
    {
    }

    public function config(): DatabaseConfig
    {
        return $this->config;
    }

    /**
     * Reports whether the target database already exists without creating it.
     */
    public function databaseExists(): bool
    {
        if ($this->config->isSqlite()) {
            return is_file((string)$this->config->sqlitePath) && filesize((string)$this->config->sqlitePath) > 0;
        }

        $pdo = $this->connectToServer();

        $sql = match ($this->config->driver) {
            DatabaseConfig::DRIVER_MYSQL => 'SELECT 1 FROM information_schema.schemata WHERE schema_name = :name',
            DatabaseConfig::DRIVER_PGSQL => 'SELECT 1 FROM pg_database WHERE datname = :name',
        };

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['name' => $this->config->database]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Creates the database if it does not exist. Returns true when it was created.
     */
    public function ensureDatabaseExists(): bool
    {
        if ($this->databaseExists()) {
            return false;
        }

        if (!$this->config->autoCreate) {
            throw new DatabaseException(
                "Database '{$this->config->describe()}' does not exist and DB_AUTO_CREATE is disabled."
            );
        }

        if ($this->config->isSqlite()) {
            $dir = dirname((string)$this->config->sqlitePath);
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new DatabaseException("Unable to create SQLite directory '{$dir}'.");
            }
            // Opening the connection creates the file.
            $this->connect();
            return true;
        }

        $pdo = $this->connectToServer();
        $name = $this->quoteIdentifier($this->config->database);

        $sql = match ($this->config->driver) {
            DatabaseConfig::DRIVER_MYSQL => "CREATE DATABASE {$name} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
            DatabaseConfig::DRIVER_PGSQL => "CREATE DATABASE {$name} ENCODING 'UTF8'",
        };

        $pdo->exec($sql);

        return true;
    }

    public function connect(): PDO
    {
        $pdo = $this->createPdo($this->config->dsn());

        if ($this->config->isSqlite()) {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        return $pdo;
    }

    private function connectToServer(): PDO
    {
        return $this->createPdo($this->config->serverDsn());
    }

    private function createPdo(string $dsn): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($this->config->driver === DatabaseConfig::DRIVER_MYSQL && $this->config->sslCa !== null) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $this->config->sslCa;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        try {
            return new PDO($dsn, $this->config->username, $this->config->password, $options);
        } catch (\PDOException $e) {
            throw new DatabaseException(
                "Could not connect to {$this->config->describe()}: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new DatabaseException("Invalid database name '{$identifier}'.");
        }

        return $this->config->driver === DatabaseConfig::DRIVER_MYSQL ? "`{$identifier}`" : "\"{$identifier}\"";
    }
}
