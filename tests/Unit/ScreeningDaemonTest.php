<?php

declare(strict_types=1);

namespace Tests\Unit;

use AI\Application\Daemon\DaemonConfig;
use AI\Application\Daemon\PolledTicket;
use AI\Application\Daemon\ScreeningDaemon;
use AI\Application\Daemon\ServiceNowIncidentPoller;
use AI\Application\Daemon\TicketProcessor;
use AI\Application\Exceptions\IncidentScreeningException;
use AI\Application\Exceptions\NoHostsFoundException;
use AI\Application\IncidentScreeningService;
use AI\Core\Config\MissingConfigurationException;
use AI\Infrastructure\Database\Migrations\Migrator;
use AI\Infrastructure\Logging\Logger;
use AI\Infrastructure\Persistence\DaemonRunRepository;
use AI\Infrastructure\Persistence\ScreeningRepository;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ServiceNOW\Client as ServiceNOW;

#[RequiresPhpExtension('pdo_sqlite')]
class ScreeningDaemonTest extends TestCase
{
    private PDO $pdo;
    private ScreeningRepository $screenings;
    private string $heartbeat;
    /** @var resource */
    private $log;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new Migrator($this->pdo, __DIR__ . '/../../database/migrations'))->migrate();
        $this->screenings = new ScreeningRepository($this->pdo);
        $this->heartbeat = sys_get_temp_dir() . '/hb_' . uniqid();
        $this->log = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        @unlink($this->heartbeat);
    }

    private function config(bool $writeBack = false, bool $runOnce = true): DaemonConfig
    {
        return new DaemonConfig(
            pollTable: 'incident',
            pollQuery: 'state=1',
            pollLimit: 10,
            pollInterval: 1,
            maxAttempts: 3,
            retryFailed: true,
            runOnce: $runOnce,
            writeBack: $writeBack,
            writeBackField: 'work_notes',
            outputDirectory: sys_get_temp_dir(),
            heartbeatFile: $this->heartbeat
        );
    }

    private function logger(): Logger
    {
        return new Logger('debug', 'text', 'test', $this->log, $this->log);
    }

    private function logOutput(): string
    {
        rewind($this->log);
        return stream_get_contents($this->log);
    }

    /**
     * @param array<int, array<string, string>> $records
     */
    private function pollerReturning(array $records): ServiceNowIncidentPoller
    {
        $snow = $this->createStub(ServiceNOW::class);
        $snow->method('RetrieveAllRecords')->willReturn(['status' => true, 'data' => $records]);
        $snow->method('UpdateRecord')->willReturn(['status' => true, 'data' => []]);

        return new ServiceNowIncidentPoller($snow, 'incident', 'state=1', 10);
    }

    private function daemon(ServiceNowIncidentPoller $poller, IncidentScreeningService $service, DaemonConfig $config): ScreeningDaemon
    {
        $processor = new TicketProcessor($service, $this->screenings, $poller, $config, $this->logger());

        return new ScreeningDaemon(
            $config,
            $poller,
            $processor,
            $this->screenings,
            new DaemonRunRepository($this->pdo),
            $this->logger(),
            static fn (int $s) => null
        );
    }

    public function testRunOnceProcessesMatchingTicketsAndSkipsAlreadyProcessed(): void
    {
        $service = $this->createMock(IncidentScreeningService::class);
        $service->expects(self::exactly(2))
            ->method('screenIncident')
            ->willReturnCallback(fn (string $n) => ScreeningRepositoryTest::sampleResult($n));

        $poller = $this->pollerReturning([
            ['number' => 'INC1', 'sys_id' => 'a', 'short_description' => 'cpu'],
            ['number' => 'INC2', 'sys_id' => 'b', 'short_description' => 'disk'],
        ]);

        $daemon = $this->daemon($poller, $service, $this->config());
        self::assertSame(0, $daemon->run());

        self::assertSame(['completed' => 2], $this->screenings->countByStatus());
        self::assertFileExists($this->heartbeat);

        // Second cycle: same tickets come back from the query but are not re-screened.
        $daemon->cycle();
        self::assertSame(['completed' => 2], $this->screenings->countByStatus());

        $run = $this->pdo->query('SELECT * FROM daemon_runs')->fetch();
        self::assertSame(2, (int)$run['polls']);
        self::assertSame(2, (int)$run['processed']);
        self::assertNotNull($run['stopped_at']);
    }

    public function testFailuresAreRecordedAndRetriedUntilMaxAttempts(): void
    {
        $service = $this->createMock(IncidentScreeningService::class);
        $service->expects(self::exactly(3))
            ->method('screenIncident')
            ->willThrowException(new \RuntimeException('LLM unavailable'));

        $poller = $this->pollerReturning([['number' => 'INC1', 'sys_id' => 'a']]);
        $daemon = $this->daemon($poller, $service, $this->config());

        for ($i = 0; $i < 5; $i++) {
            $daemon->cycle();
        }

        $row = $this->screenings->find('INC1');
        self::assertSame('failed', $row['status']);
        self::assertSame(3, (int)$row['attempts']);
        self::assertStringContainsString('LLM unavailable', $row['error_message']);
    }

    public function testNoHostsFoundIsSkippedNotRetried(): void
    {
        $service = $this->createMock(IncidentScreeningService::class);
        $service->expects(self::once())
            ->method('screenIncident')
            ->willThrowException(new NoHostsFoundException('No Zabbix hosts'));

        $daemon = $this->daemon($this->pollerReturning([['number' => 'INC1']]), $service, $this->config());
        $daemon->cycle();
        $daemon->cycle();

        self::assertSame('skipped', $this->screenings->find('INC1')['status']);
    }

    public function testWriteBackPostsReportToServiceNow(): void
    {
        $snow = $this->createMock(ServiceNOW::class);
        $snow->method('RetrieveAllRecords')->willReturn([
            'status' => true,
            'data' => [['number' => 'INC1', 'sys_id' => 'abc']],
        ]);
        $snow->expects(self::once())
            ->method('UpdateRecord')
            ->with('incident', 'abc', ['work_notes' => 'AI Agent: report'])
            ->willReturn(['status' => true, 'data' => []]);

        $poller = new ServiceNowIncidentPoller($snow, 'incident', 'state=1', 10);
        $service = $this->createStub(IncidentScreeningService::class);
        $service->method('screenIncident')->willReturn(ScreeningRepositoryTest::sampleResult('INC1'));

        $this->daemon($poller, $service, $this->config(writeBack: true))->cycle();

        self::assertNotNull($this->screenings->find('INC1')['written_back_at']);
    }

    public function testPollFailureDoesNotCrashDaemon(): void
    {
        $snow = $this->createStub(ServiceNOW::class);
        $snow->method('RetrieveAllRecords')->willReturn(['status' => false, 'Message' => 'HTTP 401']);
        $poller = new ServiceNowIncidentPoller($snow, 'incident', 'state=1', 10);

        $service = $this->createMock(IncidentScreeningService::class);
        $service->expects(self::never())->method('screenIncident');

        self::assertSame(0, $this->daemon($poller, $service, $this->config())->run());
        self::assertStringContainsString('HTTP 401', $this->logOutput());
        self::assertStringContainsString('HTTP 401', (string)$this->pdo->query('SELECT last_error FROM daemon_runs')->fetchColumn());
    }

    public function testStopEndsLoop(): void
    {
        $service = $this->createStub(IncidentScreeningService::class);
        $poller = $this->pollerReturning([]);
        $config = $this->config(runOnce: false);

        $cycles = 0;
        $processor = new TicketProcessor($service, $this->screenings, $poller, $config, $this->logger());
        $daemon = null;
        $daemon = new ScreeningDaemon(
            $config,
            $poller,
            $processor,
            $this->screenings,
            new DaemonRunRepository($this->pdo),
            $this->logger(),
            function () use (&$cycles, &$daemon): void {
                if (++$cycles >= 3) {
                    $daemon->stop();
                }
            }
        );

        self::assertSame(0, $daemon->run());
        self::assertSame(3, $cycles);
    }

    public function testPollerRejectsEmptyQuery(): void
    {
        $this->expectException(MissingConfigurationException::class);
        new DaemonConfig('incident', '   ', 10, 60, 3, true, false, false, 'work_notes', '/tmp', null);
    }

    public function testPollerThrowsOnServiceNowError(): void
    {
        $snow = $this->createStub(ServiceNOW::class);
        $snow->method('RetrieveAllRecords')->willReturn(['status' => false, 'Message' => 'boom']);

        $this->expectException(IncidentScreeningException::class);
        (new ServiceNowIncidentPoller($snow, 'incident', 'q', 1))->poll();
    }

    public function testPollerMapsRecordsAndSendsEncodedQuery(): void
    {
        $snow = $this->createMock(ServiceNOW::class);
        $snow->expects(self::once())
            ->method('RetrieveAllRecords')
            ->with('incident', self::callback(fn (array $p) => $p['sysparm_query'] === 'state=1^cat=x' && $p['sysparm_limit'] === 5))
            ->willReturn(['status' => true, 'data' => [
                ['number' => 'INC9', 'sys_id' => 's9', 'short_description' => 'd'],
                ['number' => ''],
            ]]);

        $tickets = (new ServiceNowIncidentPoller($snow, 'incident', 'state=1^cat=x', 5))->poll();

        self::assertCount(1, $tickets);
        self::assertInstanceOf(PolledTicket::class, $tickets[0]);
        self::assertSame('INC9', $tickets[0]->number);
        self::assertSame('s9', $tickets[0]->sysId);
    }
}
