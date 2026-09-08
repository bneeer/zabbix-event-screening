<?php

declare(strict_types=1);

namespace Tests\Unit;

use AI\Agents\Itsm\ItsmIncidentManager;
use AI\Agents\Zabbix\ZabbixAutoScreening;
use AI\Agents\Zabbix\ZabbixHostFinderAgent;
use AI\Agents\Zabbix\ZabbixScanner;
use AI\Application\DTO\IncidentScreeningResult;
use AI\Application\Exceptions\IncidentNotFoundException;
use AI\Application\Exceptions\IncidentScreeningException;
use AI\Application\Exceptions\NoHostsFoundException;
use AI\Application\IncidentScreeningService;
use AI\Core\DTO\ScreeningStatus;
use AI\Core\Security\CommandValidationException;
use AI\Core\Security\CommandValidator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\StreamResponse;
use PHPUnit\Framework\TestCase;
use ServiceNOW\Client as ServiceNOW;

class IncidentScreeningServiceTest extends TestCase
{
    private function createStreamWithChunks(array $texts): StreamResponse
    {
        $generator = (function () use ($texts) {
            foreach ($texts as $text) {
                yield new TextChunk($text);
            }
        })();

        return new StreamResponse($generator);
    }

    public function testSuccessfulEndToEndIncidentScreening(): void
    {
        $mockSnow = $this->createMock(ServiceNOW::class);
        $mockSnow->method('RetrieveRecord')
            ->with('incident', 'INC09827002')
            ->willReturn([
                'status' => true,
                'data' => [
                    ['number' => 'INC09827002']
                ]
            ]);

        $mockIncidentManager = $this->createMock(ItsmIncidentManager::class);
        $mockIncidentManager->method('stream')
            ->willReturn($this->createStreamWithChunks([
                'Incident INC09827002 reports high CPU load on host srv-linux-01.'
            ]));

        $mockHostFinder = $this->createMock(ZabbixHostFinderAgent::class);
        $hostFinderMessage = new AssistantMessage(json_encode([
            'hostnames' => ['srv-linux-01'],
            'hosts' => [
                [
                    'hostid' => '10001',
                    'host' => 'srv-linux-01',
                    'name' => 'srv-linux-01',
                    'operating_system' => 'Linux'
                ]
            ]
        ]));
        $mockHostFinderAgentResponse = $this->createMock(\NeuronAI\Agent\AgentResponse::class);
        $mockHostFinderAgentResponse->method('getMessage')->willReturn($hostFinderMessage);
        $mockHostFinder->method('chat')->willReturn($mockHostFinderAgentResponse);

        $mockAutoScreening = $this->createMock(ZabbixAutoScreening::class);
        $screeningPlanMessage = new AssistantMessage(json_encode([
            'customer' => 'Enterprise Corp',
            'analysis' => 'Check CPU and load average.',
            'commands' => [
                'uptime',
                'top -b -n 1'
            ]
        ]));
        $mockAutoScreeningResponse = $this->createMock(\NeuronAI\Agent\AgentResponse::class);
        $mockAutoScreeningResponse->method('getMessage')->willReturn($screeningPlanMessage);
        $mockAutoScreening->method('chat')->willReturn($mockAutoScreeningResponse);

        $scannerOutput = json_encode([
            'status' => '######SUCCESS########',
            'root_cause' => 'CPU saturation caused by runaway process pid 4501.',
            'suggested_next_steps' => 'Terminate process 4501 and review cronjob.',
            'technical_evidence' => "14:00:01 up 20 days, load average: 8.50, 6.20, 4.10",
            'affected_hosts' => ['10001'],
            'command_execution_results' => [
                [
                    'command' => 'uptime',
                    'output' => '14:00:01 up 20 days, load average: 8.50, 6.20, 4.10',
                    'status' => 'success',
                    'host_id' => '10001'
                ]
            ]
        ]);

        $mockScanner = $this->createMock(ZabbixScanner::class);
        $mockScanner->method('stream')
            ->willReturn($this->createStreamWithChunks([$scannerOutput]));

        $progressLog = [];
        $service = new IncidentScreeningService(
            serviceNowClient: $mockSnow,
            incidentManager: $mockIncidentManager,
            hostFinderAgent: $mockHostFinder,
            autoScreeningAgent: $mockAutoScreening,
            scannerAgent: $mockScanner,
            commandValidator: new CommandValidator()
        );

        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'screening_test_' . uniqid();
        $result = $service->screenIncident(
            incidentNumber: 'INC09827002',
            outputDirectory: $tempDir,
            onProgress: function (string $msg) use (&$progressLog) {
                $progressLog[] = $msg;
            }
        );

        $this->assertInstanceOf(IncidentScreeningResult::class, $result);
        $this->assertSame('INC09827002', $result->incidentNumber);
        $this->assertSame(['10001' => 'Linux'], $result->hostIdsToOsMap);
        $this->assertSame(ScreeningStatus::SUCCESS, $result->screeningResult->status);
        $this->assertTrue($result->screeningResult->isSuccess());
        $this->assertStringContainsString('CPU saturation', $result->getScannerResult()->rootCause);
        $this->assertStringContainsString('Terminate process', $result->getScannerResult()->suggestedNextSteps);
        $this->assertStringContainsString('######SUCCESS########', $result->getFormattedReport());
        $this->assertStringContainsString('THIS MESSAGE WAS GENERATED BY AN AUTONOMOUS AI-AGENT', $result->getItsmOutput());
        $this->assertNotEmpty($progressLog);
        $this->assertNotNull($result->outputFilePath);
        $this->assertFileExists($result->outputFilePath);

        // Cleanup
        if (file_exists($result->outputFilePath)) {
            @unlink($result->outputFilePath);
        }
        if (is_dir($tempDir)) {
            @rmdir($tempDir);
        }
    }

    public function testIncidentNotFoundThrowsException(): void
    {
        $mockSnow = $this->createMock(ServiceNOW::class);
        $mockSnow->method('RetrieveRecord')
            ->willReturn([
                'status' => false,
                'Message' => 'Incident INC99999999 not found'
            ]);

        $service = new IncidentScreeningService(serviceNowClient: $mockSnow);

        $this->expectException(IncidentNotFoundException::class);
        $this->expectExceptionMessage('Incident INC99999999 not found');

        $service->screenIncident('INC99999999');
    }

    public function testNoHostsFoundThrowsException(): void
    {
        $mockSnow = $this->createMock(ServiceNOW::class);
        $mockSnow->method('RetrieveRecord')
            ->willReturn([
                'status' => true,
                'data' => [['number' => 'INC09827002']]
            ]);

        $mockIncidentManager = $this->createMock(ItsmIncidentManager::class);
        $mockIncidentManager->method('stream')
            ->willReturn($this->createStreamWithChunks(['Network incident with no servers specified.']));

        $mockHostFinder = $this->createMock(ZabbixHostFinderAgent::class);
        $hostFinderMessage = new AssistantMessage(json_encode([
            'hostnames' => [],
            'hosts' => []
        ]));
        $mockHostFinderAgentResponse = $this->createMock(\NeuronAI\Agent\AgentResponse::class);
        $mockHostFinderAgentResponse->method('getMessage')->willReturn($hostFinderMessage);
        $mockHostFinder->method('chat')->willReturn($mockHostFinderAgentResponse);

        $service = new IncidentScreeningService(
            serviceNowClient: $mockSnow,
            incidentManager: $mockIncidentManager,
            hostFinderAgent: $mockHostFinder
        );

        $this->expectException(NoHostsFoundException::class);
        $this->expectExceptionMessage('No Zabbix hosts found for incident INC09827002');

        $service->screenIncident('INC09827002');
    }

    public function testDangerousCommandRejectedBySecurityValidation(): void
    {
        $mockSnow = $this->createMock(ServiceNOW::class);
        $mockSnow->method('RetrieveRecord')
            ->willReturn([
                'status' => true,
                'data' => [['number' => 'INC09827002']]
            ]);

        $mockIncidentManager = $this->createMock(ItsmIncidentManager::class);
        $mockIncidentManager->method('stream')
            ->willReturn($this->createStreamWithChunks(['Incident on Linux server.']));

        $mockHostFinder = $this->createMock(ZabbixHostFinderAgent::class);
        $hostFinderMessage = new AssistantMessage(json_encode([
            'hostnames' => ['srv-linux-01'],
            'hosts' => [
                [
                    'hostid' => '10001',
                    'host' => 'srv-linux-01',
                    'name' => 'srv-linux-01',
                    'operating_system' => 'Linux'
                ]
            ]
        ]));
        $mockHostFinderAgentResponse = $this->createMock(\NeuronAI\Agent\AgentResponse::class);
        $mockHostFinderAgentResponse->method('getMessage')->willReturn($hostFinderMessage);
        $mockHostFinder->method('chat')->willReturn($mockHostFinderAgentResponse);

        $mockAutoScreening = $this->createMock(ZabbixAutoScreening::class);
        $screeningPlanMessage = new AssistantMessage(json_encode([
            'customer' => 'Enterprise Corp',
            'analysis' => 'Malicious command attempted',
            'commands' => [
                'rm -rf /var/log'
            ]
        ]));
        $mockAutoScreeningResponse = $this->createMock(\NeuronAI\Agent\AgentResponse::class);
        $mockAutoScreeningResponse->method('getMessage')->willReturn($screeningPlanMessage);
        $mockAutoScreening->method('chat')->willReturn($mockAutoScreeningResponse);

        $service = new IncidentScreeningService(
            serviceNowClient: $mockSnow,
            incidentManager: $mockIncidentManager,
            hostFinderAgent: $mockHostFinder,
            autoScreeningAgent: $mockAutoScreening,
            commandValidator: new CommandValidator()
        );

        $this->expectException(CommandValidationException::class);

        $service->screenIncident('INC09827002');
    }

    public function testUnconfiguredServiceNowClientThrows(): void
    {
        $service = new IncidentScreeningService();

        $this->expectException(IncidentScreeningException::class);
        $this->expectExceptionMessage('ServiceNOW client is not configured');

        $service->screenIncident('INC09827002');
    }
}
