<?php

declare(strict_types=1);

namespace AI\Infrastructure\Database;

use AI\Core\Config\Env;
use AI\Core\Config\MissingConfigurationException;

final readonly class DatabaseConfig
{
    public const DRIVER_SQLITE = 'sqlite';
    public const DRIVER_MYSQL = 'mysql';
    public const DRIVER_PGSQL = 'pgsql';

    public function __construct(
        public string $driver,
        public ?string $host = null,
        public ?int $port = null,
        public ?string $database = null,
        public ?string $username = null,
        public ?string $password = null,
        public ?string $sqlitePath = null,
        public bool $autoCreate = true,
        public ?string $sslCa = null
    ) {
        if (!in_array($driver, [self::DRIVER_SQLITE, self::DRIVER_MYSQL, self::DRIVER_PGSQL], true)) {
            throw new MissingConfigurationException("Unsupported DB_DRIVER '{$driver}'. Use sqlite, mysql or pgsql.");
        }

        if ($driver === self::DRIVER_SQLITE) {
            if ($sqlitePath === null || $sqlitePath === '') {
                throw new MissingConfigurationException('DB_SQLITE_PATH must be set when using the sqlite driver.');
            }
        } else {
            foreach (['host' => $host, 'database' => $database, 'username' => $username] as $field => $value) {
                if ($value === null || $value === '') {
                    throw new MissingConfigurationException(
                        "DB_" . strtoupper($field === 'database' ? 'name' : ($field === 'username' ? 'user' : $field))
                        . " is required for the {$driver} driver."
                    );
                }
            }
        }
    }

    /**
     * Builds the configuration from the environment.
     *
     * Rule: explicit DB_DRIVER wins. Otherwise, remote credentials (DB_HOST) select
     * MySQL by default, and their absence falls back to a local SQLite file.
     */
    public static function fromEnvironment(): self
    {
        $driver = strtolower((string)Env::get('DB_DRIVER', ''));
        $host = Env::get('DB_HOST');

        if ($driver === '') {
            $driver = ($host !== null) ? self::DRIVER_MYSQL : self::DRIVER_SQLITE;
        }

        $storage = rtrim((string)Env::get('APP_STORAGE_PATH', dirname(__DIR__, 4) . '/storage'), '/\\');
        $defaultPort = match ($driver) {
            self::DRIVER_MYSQL => 3306,
            self::DRIVER_PGSQL => 5432,
            default => null,
        };

        return new self(
            driver: $driver,
            host: $host,
            port: $defaultPort !== null ? Env::int('DB_PORT', $defaultPort) : null,
            database: Env::get('DB_NAME', 'zabbix_event_screening'),
            username: Env::get('DB_USER'),
            password: Env::get('DB_PASSWORD'),
            sqlitePath: Env::get('DB_SQLITE_PATH', $storage . '/database.sqlite'),
            autoCreate: Env::bool('DB_AUTO_CREATE', true),
            sslCa: Env::get('DB_SSL_CA')
        );
    }

    public function isSqlite(): bool
    {
        return $this->driver === self::DRIVER_SQLITE;
    }

    /**
     * DSN pointing at the configured database.
     */
    public function dsn(): string
    {
        return $this->buildDsn($this->database);
    }

    /**
     * DSN pointing at the server only (no database), used to create the database.
     */
    public function serverDsn(): string
    {
        return $this->buildDsn($this->driver === self::DRIVER_PGSQL ? 'postgres' : null);
    }

    private function buildDsn(?string $database): string
    {
        return match ($this->driver) {
            self::DRIVER_SQLITE => 'sqlite:' . $this->sqlitePath,
            self::DRIVER_MYSQL => sprintf(
                'mysql:host=%s;port=%d;charset=utf8mb4%s',
                $this->host,
                $this->port,
                $database !== null ? ";dbname={$database}" : ''
            ),
            self::DRIVER_PGSQL => sprintf(
                'pgsql:host=%s;port=%d%s%s',
                $this->host,
                $this->port,
                $database !== null ? ";dbname={$database}" : '',
                $this->sslCa !== null ? ";sslmode=verify-full;sslrootcert={$this->sslCa}" : ''
            ),
        };
    }

    /**
     * Description safe for logs (no password).
     */
    public function describe(): string
    {
        return $this->isSqlite()
            ? "sqlite://{$this->sqlitePath}"
            : sprintf('%s://%s@%s:%d/%s', $this->driver, $this->username, $this->host, $this->port, $this->database);
    }
}
