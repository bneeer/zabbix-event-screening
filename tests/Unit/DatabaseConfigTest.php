<?php

declare(strict_types=1);

namespace Tests\Unit;

use AI\Core\Config\Env;
use AI\Core\Config\MissingConfigurationException;
use AI\Infrastructure\Database\DatabaseConfig;
use PHPUnit\Framework\TestCase;

class DatabaseConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Env::clearOverrides();
    }

    public function testFallsBackToSqliteWhenNoRemoteCredentials(): void
    {
        Env::set('DB_DRIVER', null);
        Env::set('DB_HOST', null);
        Env::set('APP_STORAGE_PATH', '/data');

        $config = DatabaseConfig::fromEnvironment();

        self::assertTrue($config->isSqlite());
        self::assertSame('sqlite:/data/database.sqlite', $config->dsn());
    }

    public function testDbHostSelectsMysqlByDefault(): void
    {
        Env::set('DB_HOST', 'db.internal');
        Env::set('DB_USER', 'app');
        Env::set('DB_PASSWORD', 's3cr3t');
        Env::set('DB_NAME', 'screening');

        $config = DatabaseConfig::fromEnvironment();

        self::assertSame(DatabaseConfig::DRIVER_MYSQL, $config->driver);
        self::assertSame(3306, $config->port);
        self::assertSame('mysql:host=db.internal;port=3306;charset=utf8mb4;dbname=screening', $config->dsn());
        self::assertSame('mysql:host=db.internal;port=3306;charset=utf8mb4', $config->serverDsn());
        self::assertStringNotContainsString('s3cr3t', $config->describe());
    }

    public function testExplicitPgsqlDriverWithTls(): void
    {
        Env::set('DB_DRIVER', 'pgsql');
        Env::set('DB_HOST', 'pg.internal');
        Env::set('DB_USER', 'app');
        Env::set('DB_NAME', 'screening');
        Env::set('DB_SSL_CA', '/certs/ca.pem');

        $config = DatabaseConfig::fromEnvironment();

        self::assertSame(5432, $config->port);
        self::assertSame(
            'pgsql:host=pg.internal;port=5432;dbname=screening;sslmode=verify-full;sslrootcert=/certs/ca.pem',
            $config->dsn()
        );
        self::assertStringContainsString('dbname=postgres', $config->serverDsn());
    }

    public function testRemoteDriverRequiresCredentials(): void
    {
        Env::set('DB_DRIVER', 'mysql');
        Env::set('DB_HOST', 'db.internal');
        Env::set('DB_USER', null);

        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('DB_USER');
        DatabaseConfig::fromEnvironment();
    }

    public function testUnknownDriverIsRejected(): void
    {
        Env::set('DB_DRIVER', 'oracle');

        $this->expectException(MissingConfigurationException::class);
        DatabaseConfig::fromEnvironment();
    }
}
