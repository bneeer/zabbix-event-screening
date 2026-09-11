<?php

declare(strict_types=1);

namespace AI\Infrastructure\Persistence;

use PDO;

/**
 * Tracks daemon instances for observability (which host/pid is polling, when it
 * last polled, how many tickets it processed).
 */
final class DaemonRunRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function start(string $hostname, int $pid): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO daemon_runs (hostname, pid, started_at, polls, processed, failed)
             VALUES (:hostname, :pid, :started_at, 0, 0, 0)'
        );
        $stmt->execute(['hostname' => $hostname, 'pid' => $pid, 'started_at' => gmdate('Y-m-d H:i:s')]);

        return (int)$this->pdo->lastInsertId($this->sequenceName());
    }

    public function recordPoll(int $runId, int $processed, int $failed, ?string $lastError = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE daemon_runs
                SET last_poll_at = :now, polls = polls + 1, processed = processed + :processed,
                    failed = failed + :failed, last_error = COALESCE(:error, last_error)
              WHERE id = :id'
        );
        $stmt->execute([
            'now' => gmdate('Y-m-d H:i:s'),
            'processed' => $processed,
            'failed' => $failed,
            'error' => $lastError,
            'id' => $runId,
        ]);
    }

    public function stop(int $runId): void
    {
        $stmt = $this->pdo->prepare('UPDATE daemon_runs SET stopped_at = :now WHERE id = :id');
        $stmt->execute(['now' => gmdate('Y-m-d H:i:s'), 'id' => $runId]);
    }

    private function sequenceName(): ?string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'daemon_runs_id_seq' : null;
    }
}
