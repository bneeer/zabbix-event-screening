<?php

declare(strict_types=1);

namespace AI\Infrastructure\Persistence;

use AI\Application\DTO\IncidentScreeningResult;
use PDO;

/**
 * Persists the lifecycle of each screened ticket. Also acts as the work queue:
 * claim() uses an atomic conditional UPDATE so several daemon replicas sharing a
 * database never process the same ticket twice.
 */
final class ScreeningRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Registers a ticket as pending if it is unknown. Returns true when inserted.
     */
    public function register(string $incidentNumber, ?string $sysId, string $sourceTable, ?string $shortDescription): bool
    {
        if ($this->find($incidentNumber) !== null) {
            return false;
        }

        $now = $this->now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO screenings
                (incident_number, incident_sys_id, source_table, status, attempts, short_description, created_at, updated_at)
             VALUES (:number, :sys_id, :source_table, :status, 0, :short_description, :created_at, :updated_at)'
        );

        try {
            $stmt->execute([
                'number' => $incidentNumber,
                'sys_id' => $sysId,
                'source_table' => $sourceTable,
                'status' => ScreeningStatus::PENDING->value,
                'short_description' => $shortDescription,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\PDOException $e) {
            // Unique violation: another replica registered it first.
            if (in_array((string)$e->getCode(), ['23000', '23505'], true)) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $incidentNumber): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM screenings WHERE incident_number = :number');
        $stmt->execute(['number' => $incidentNumber]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Decides whether a ticket should be (re)processed.
     */
    public function isEligible(string $incidentNumber, int $maxAttempts, bool $retryFailed): bool
    {
        $row = $this->find($incidentNumber);

        if ($row === null) {
            return true;
        }

        return match ($row['status']) {
            ScreeningStatus::PENDING->value => true,
            ScreeningStatus::FAILED->value => $retryFailed && (int)$row['attempts'] < $maxAttempts,
            default => false,
        };
    }

    /**
     * Atomically moves a ticket from pending/failed to running. Returns false when
     * another worker already claimed it.
     */
    public function claim(string $incidentNumber): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE screenings
                SET status = :running, attempts = attempts + 1, started_at = :now, finished_at = NULL,
                    error_message = NULL, updated_at = :now2
              WHERE incident_number = :number AND status IN (:pending, :failed)'
        );
        $now = $this->now();
        $stmt->execute([
            'running' => ScreeningStatus::RUNNING->value,
            'now' => $now,
            'now2' => $now,
            'number' => $incidentNumber,
            'pending' => ScreeningStatus::PENDING->value,
            'failed' => ScreeningStatus::FAILED->value,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function markCompleted(string $incidentNumber, IncidentScreeningResult $result): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE screenings
                SET status = :status, incident_summary = :summary, host_ids = :host_ids,
                    diagnostic_plan = :plan, itsm_output = :itsm, raw_output = :raw,
                    output_file = :output_file, finished_at = :now, updated_at = :now2
              WHERE incident_number = :number'
        );
        $now = $this->now();
        $stmt->execute([
            'status' => ScreeningStatus::COMPLETED->value,
            'summary' => $result->incidentSummary,
            'host_ids' => $this->json($result->hostIdsToOsMap),
            'plan' => $this->json($result->diagnosticPlan),
            'itsm' => $result->itsmOutput,
            'raw' => $result->rawScannerOutput,
            'output_file' => $result->outputFilePath,
            'now' => $now,
            'now2' => $now,
            'number' => $incidentNumber,
        ]);
    }

    public function markFailed(string $incidentNumber, string $error): void
    {
        $this->finish($incidentNumber, ScreeningStatus::FAILED, $error);
    }

    /**
     * Terminal state for tickets we deliberately will not retry (e.g. no hosts found).
     */
    public function markSkipped(string $incidentNumber, string $reason): void
    {
        $this->finish($incidentNumber, ScreeningStatus::SKIPPED, $reason);
    }

    public function markWrittenBack(string $incidentNumber): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE screenings SET written_back_at = :now, updated_at = :now2 WHERE incident_number = :number'
        );
        $now = $this->now();
        $stmt->execute(['now' => $now, 'now2' => $now, 'number' => $incidentNumber]);
    }

    /**
     * Tickets left in "running" by a crashed worker are returned to "failed" so they
     * become eligible again (subject to the attempts limit).
     */
    public function releaseStale(int $olderThanSeconds): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE screenings
                SET status = :failed, error_message = :error, updated_at = :now
              WHERE status = :running AND started_at < :threshold'
        );
        $stmt->execute([
            'failed' => ScreeningStatus::FAILED->value,
            'error' => 'Released: worker did not finish in time.',
            'now' => $this->now(),
            'running' => ScreeningStatus::RUNNING->value,
            'threshold' => gmdate('Y-m-d H:i:s', time() - $olderThanSeconds),
        ]);

        return $stmt->rowCount();
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $rows = $this->pdo->query('SELECT status, COUNT(*) AS total FROM screenings GROUP BY status')->fetchAll();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)$row['status']] = (int)$row['total'];
        }

        return $counts;
    }

    private function finish(string $incidentNumber, ScreeningStatus $status, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE screenings
                SET status = :status, error_message = :error, finished_at = :now, updated_at = :now2
              WHERE incident_number = :number'
        );
        $now = $this->now();
        $stmt->execute([
            'status' => $status->value,
            'error' => mb_substr($message, 0, 4000),
            'now' => $now,
            'now2' => $now,
            'number' => $incidentNumber,
        ]);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'null';
    }
}
