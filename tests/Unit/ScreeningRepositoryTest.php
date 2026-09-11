<?php

declare(strict_types=1);

namespace Tests\Unit;

use AI\Application\DTO\IncidentScreeningResult;
use AI\Core\DTO\DiagnosticCommand;
use AI\Core\DTO\DiagnosticPlan;
use AI\Core\DTO\ScannerResult;
use AI\Core\DTO\ScreeningStatus;
use AI\Core\DTO\ZabbixHostMatchResult;
use AI\Infrastructure\Database\Migrations\Migrator;
use AI\Infrastructure\Persistence\ScreeningRepository;
use AI\Infrastructure\Persistence\ScreeningStatus as PersistedStatus;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class ScreeningRepositoryTest extends TestCase
{
    private PDO $pdo;
    private ScreeningRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new Migrator($this->pdo, __DIR__ . '/../../database/migrations'))->migrate();
        $this->repo = new ScreeningRepository($this->pdo);
    }

    public static function sampleResult(string $number): IncidentScreeningResult
    {
        return new IncidentScreeningResult(
            incidentNumber: $number,
            incidentSummary: 'High CPU on srv-01',
            hostMatchResult: new ZabbixHostMatchResult(['srv-01'], []),
            hostIdsToOsMap: ['10001' => 'Linux'],
            diagnosticPlan: new DiagnosticPlan('Corp', 'Check CPU', [new DiagnosticCommand('uptime')], ['10001' => 'Linux']),
            screeningResult: new ScannerResult(ScreeningStatus::SUCCESS, 'Runaway process', 'Restart service'),
            rawScannerOutput: 'raw',
            itsmOutput: 'AI Agent: report',
            outputFilePath: '/tmp/x.txt'
        );
    }

    public function testRegisterIsIdempotent(): void
    {
        self::assertTrue($this->repo->register('INC1', 'sys1', 'incident', 'desc'));
        self::assertFalse($this->repo->register('INC1', 'sys1', 'incident', 'desc'));

        $row = $this->repo->find('INC1');
        self::assertSame(PersistedStatus::PENDING->value, $row['status']);
        self::assertSame(0, (int)$row['attempts']);
    }

    public function testClaimIsExclusive(): void
    {
        $this->repo->register('INC1', null, 'incident', null);

        self::assertTrue($this->repo->claim('INC1'));
        self::assertFalse($this->repo->claim('INC1'), 'a running ticket cannot be claimed twice');
        self::assertSame(1, (int)$this->repo->find('INC1')['attempts']);
    }

    public function testCompletedTicketsAreNotEligible(): void
    {
        $this->repo->register('INC1', null, 'incident', null);
        $this->repo->claim('INC1');
        $this->repo->markCompleted('INC1', self::sampleResult('INC1'));

        $row = $this->repo->find('INC1');
        self::assertSame(PersistedStatus::COMPLETED->value, $row['status']);
        self::assertSame('{"10001":"Linux"}', $row['host_ids']);
        self::assertSame('AI Agent: report', $row['itsm_output']);
        self::assertFalse($this->repo->isEligible('INC1', 3, true));
    }

    public function testFailedTicketsRetryUntilMaxAttempts(): void
    {
        $this->repo->register('INC1', null, 'incident', null);

        $this->repo->claim('INC1');
        $this->repo->markFailed('INC1', 'boom');
        self::assertTrue($this->repo->isEligible('INC1', 2, true));
        self::assertFalse($this->repo->isEligible('INC1', 2, false), 'retries disabled');

        $this->repo->claim('INC1');
        $this->repo->markFailed('INC1', 'boom again');
        self::assertFalse($this->repo->isEligible('INC1', 2, true), 'max attempts reached');
        self::assertSame('boom again', $this->repo->find('INC1')['error_message']);
    }

    public function testSkippedIsTerminal(): void
    {
        $this->repo->register('INC1', null, 'incident', null);
        $this->repo->claim('INC1');
        $this->repo->markSkipped('INC1', 'no hosts');

        self::assertFalse($this->repo->isEligible('INC1', 99, true));
        self::assertSame(['skipped' => 1], $this->repo->countByStatus());
    }

    public function testReleaseStaleReturnsRunningTicketsToFailed(): void
    {
        $this->repo->register('INC1', null, 'incident', null);
        $this->repo->claim('INC1');
        $this->pdo->exec("UPDATE screenings SET started_at = '2000-01-01 00:00:00'");

        self::assertSame(1, $this->repo->releaseStale(3600));
        self::assertSame(PersistedStatus::FAILED->value, $this->repo->find('INC1')['status']);
        self::assertTrue($this->repo->isEligible('INC1', 3, true));
    }
}
