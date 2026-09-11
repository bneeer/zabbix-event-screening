<?php

declare(strict_types=1);

namespace AI\Infrastructure\Database\Migrations;

use AI\Infrastructure\Database\DatabaseException;
use PDO;

/**
 * File-based migration runner.
 *
 * Each file in the migrations directory must return an object implementing
 * Migration and be named "<version>_<description>.php" (version sorts lexically,
 * e.g. 2026_09_11_000001). Applied versions are recorded in `schema_migrations`.
 */
final class Migrator
{
    public const TABLE = 'schema_migrations';

    private Dialect $dialect;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath,
        ?Dialect $dialect = null
    ) {
        $this->dialect = $dialect ?? new Dialect($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function schemaExists(): bool
    {
        return $this->tableExists(self::TABLE);
    }

    public function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare($this->dialect->tableExistsQuery());
        $stmt->execute(['name' => $table]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Applies all pending migrations and returns the applied versions.
     *
     * @return string[]
     */
    public function migrate(): array
    {
        $this->ensureSchemaTable();

        $applied = [];
        foreach ($this->pending() as $version => $file) {
            $this->apply($version, $file);
            $applied[] = $version;
        }

        return $applied;
    }

    /**
     * Rolls back the most recently applied migration, if any.
     */
    public function rollback(): ?string
    {
        if (!$this->schemaExists()) {
            return null;
        }

        $version = $this->pdo->query(
            'SELECT version FROM ' . self::TABLE . ' ORDER BY version DESC LIMIT 1'
        )->fetchColumn();

        if ($version === false) {
            return null;
        }

        $files = $this->discover();
        if (!isset($files[$version])) {
            throw new DatabaseException("Migration file for applied version '{$version}' not found.");
        }

        $migration = $this->load($files[$version]);

        $this->transactional(function () use ($migration, $version): void {
            $migration->down($this->pdo, $this->dialect);
            $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE version = :version');
            $stmt->execute(['version' => $version]);
        });

        return (string)$version;
    }

    /**
     * @return array<string, string> version => absolute file path
     */
    public function pending(): array
    {
        $applied = $this->appliedVersions();

        return array_filter(
            $this->discover(),
            static fn (string $version): bool => !in_array($version, $applied, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @return string[]
     */
    public function appliedVersions(): array
    {
        if (!$this->schemaExists()) {
            return [];
        }

        return array_map(
            'strval',
            $this->pdo->query('SELECT version FROM ' . self::TABLE . ' ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /**
     * @return array<string, string> version => absolute file path, sorted by version
     */
    public function discover(): array
    {
        if (!is_dir($this->migrationsPath)) {
            throw new DatabaseException("Migrations directory '{$this->migrationsPath}' not found.");
        }

        $files = [];
        foreach (glob(rtrim($this->migrationsPath, '/\\') . '/*.php') ?: [] as $file) {
            $basename = basename($file, '.php');
            if (!preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_[a-z0-9_]+$/i', $basename, $m)) {
                throw new DatabaseException("Invalid migration file name '{$basename}'. Expected YYYY_MM_DD_NNNNNN_description.php");
            }
            $files[$m[1]] = $file;
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    private function ensureSchemaTable(): void
    {
        if ($this->schemaExists()) {
            return;
        }

        $d = $this->dialect;
        $this->pdo->exec(sprintf(
            'CREATE TABLE %s (version %s NOT NULL PRIMARY KEY, applied_at %s NOT NULL)%s',
            self::TABLE,
            $d->string(32),
            $d->datetime(),
            $d->tableOptions()
        ));
    }

    private function apply(string $version, string $file): void
    {
        $migration = $this->load($file);

        $this->transactional(function () use ($migration, $version): void {
            $migration->up($this->pdo, $this->dialect);
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE . ' (version, applied_at) VALUES (:version, :applied_at)'
            );
            $stmt->execute(['version' => $version, 'applied_at' => gmdate('Y-m-d H:i:s')]);
        });
    }

    private function load(string $file): Migration
    {
        $migration = require $file;

        if (!$migration instanceof Migration) {
            throw new DatabaseException("Migration file '{$file}' must return an instance of " . Migration::class);
        }

        return $migration;
    }

    /**
     * MySQL auto-commits DDL, so transactions only protect SQLite/PostgreSQL. That is
     * still worth it; on MySQL a failed migration is simply not recorded and re-runs.
     */
    private function transactional(callable $fn): void
    {
        $useTransaction = $this->dialect->driver !== 'mysql';

        if ($useTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $fn();
            if ($useTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($useTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new DatabaseException('Migration failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
