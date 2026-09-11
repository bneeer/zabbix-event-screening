<?php

declare(strict_types=1);

namespace AI\Infrastructure\Database;

use AI\Infrastructure\Database\Migrations\Migrator;
use AI\Infrastructure\Logging\Logger;
use PDO;

/**
 * Container start-up routine: verify whether the database already exists,
 * create it only when missing, then bring the schema up to date.
 */
final class DatabaseBootstrapper
{
    public function __construct(
        private readonly ConnectionFactory $factory,
        private readonly string $migrationsPath,
        private readonly Logger $logger
    ) {
    }

    public function bootstrap(): PDO
    {
        $config = $this->factory->config();
        $this->logger->info('Checking database', ['target' => $config->describe()]);

        if ($this->factory->databaseExists()) {
            $this->logger->info('Database already exists, reusing it.');
        } else {
            $this->factory->ensureDatabaseExists();
            $this->logger->info('Database did not exist and was created.');
        }

        $pdo = $this->factory->connect();
        $migrator = new Migrator($pdo, $this->migrationsPath);

        if (!$migrator->schemaExists()) {
            $this->logger->info('Schema not initialised yet, running all migrations.');
        }

        $applied = $migrator->migrate();

        if ($applied === []) {
            $this->logger->info('Schema is up to date.', ['versions' => count($migrator->appliedVersions())]);
        } else {
            $this->logger->info('Applied migrations.', ['applied' => $applied]);
        }

        return $pdo;
    }
}
