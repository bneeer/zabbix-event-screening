<?php

declare(strict_types=1);

namespace Tests\Unit;

use AI\Infrastructure\Database\ConnectionFactory;
use AI\Infrastructure\Database\DatabaseBootstrapper;
use AI\Infrastructure\Database\DatabaseConfig;
use AI\Infrastructure\Database\DatabaseException;
use AI\Infrastructure\Database\Migrations\Migrator;
use AI\Infrastructure\Logging\Logger;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class MigratorTest extends TestCase
{
    private const MIGRATIONS = __DIR__ . '/../../database/migrations';

    private function memoryPdo(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function testMigrateCreatesSchemaAndIsIdempotent(): void
    {
        $pdo = $this->memoryPdo();
        $migrator = new Migrator($pdo, self::MIGRATIONS);

        self::assertFalse($migrator->schemaExists());
        self::assertCount(2, $migrator->pending());

        $applied = $migrator->migrate();

        self::assertSame(['2026_09_11_000001', '2026_09_11_000002'], $applied);
        self::assertTrue($migrator->schemaExists());
        self::assertTrue($migrator->tableExists('screenings'));
        self::assertTrue($migrator->tableExists('daemon_runs'));
        self::assertSame([], $migrator->pending());

        self::assertSame([], $migrator->migrate(), 'second run must be a no-op');
        self::assertSame($applied, $migrator->appliedVersions());
    }

    public function testRollbackRemovesLastMigration(): void
    {
        $pdo = $this->memoryPdo();
        $migrator = new Migrator($pdo, self::MIGRATIONS);
        $migrator->migrate();

        self::assertSame('2026_09_11_000002', $migrator->rollback());
        self::assertFalse($migrator->tableExists('daemon_runs'));
        self::assertTrue($migrator->tableExists('screenings'));
        self::assertCount(1, $migrator->pending());
    }

    public function testRejectsBadlyNamedMigrationFiles(): void
    {
        $dir = sys_get_temp_dir() . '/migr_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/bad_name.php', '<?php return null;');

        try {
            $this->expectException(DatabaseException::class);
            (new Migrator($this->memoryPdo(), $dir))->discover();
        } finally {
            unlink($dir . '/bad_name.php');
            rmdir($dir);
        }
    }

    public function testBootstrapperCreatesSqliteFileOnlyWhenMissing(): void
    {
        $path = sys_get_temp_dir() . '/screening_' . uniqid() . '/db.sqlite';
        $config = new DatabaseConfig(driver: 'sqlite', sqlitePath: $path);
        $factory = new ConnectionFactory($config);
        $log = fopen('php://memory', 'w+');
        $logger = new Logger('info', 'text', 'db', $log, $log);

        try {
            self::assertFalse($factory->databaseExists());

            (new DatabaseBootstrapper($factory, self::MIGRATIONS, $logger))->bootstrap();
            self::assertTrue($factory->databaseExists());

            (new DatabaseBootstrapper($factory, self::MIGRATIONS, $logger))->bootstrap();

            rewind($log);
            $output = stream_get_contents($log);
            self::assertStringContainsString('was created', $output);
            self::assertStringContainsString('already exists, reusing', $output);
            self::assertStringContainsString('up to date', $output);
        } finally {
            foreach (glob(dirname($path) . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir(dirname($path));
        }
    }

    public function testAutoCreateDisabledFailsWhenDatabaseMissing(): void
    {
        $path = sys_get_temp_dir() . '/screening_' . uniqid() . '.sqlite';
        $factory = new ConnectionFactory(new DatabaseConfig(driver: 'sqlite', sqlitePath: $path, autoCreate: false));

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('DB_AUTO_CREATE');
        $factory->ensureDatabaseExists();
    }
}
