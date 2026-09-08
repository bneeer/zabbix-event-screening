<?php

declare(strict_types=1);

namespace Tests\Unit;

use AI\Core\DTO\CommandExecutionResult;
use AI\Core\DTO\Parser\ScannerResultParser;
use AI\Core\DTO\Parser\ScreeningResultParser;
use AI\Core\DTO\ScannerResult;
use AI\Core\DTO\ScreeningStatus;
use AI\Core\Exceptions\AiContractValidationException;
use AI\Core\Formatters\ConsoleFormatter;
use AI\Core\Formatters\JsonFormatter;
use AI\Core\Formatters\ServiceNowFormatter;
use PHPUnit\Framework\TestCase;

class ScannerResultTest extends TestCase
{
    public function testParsesValidStructuredJsonResponse(): void
    {
        $json = json_encode([
            'status' => '######SUCCESS########',
            'root_cause' => 'Postgres database connection pool exhaustion on DB master.',
            'suggested_next_steps' => 'Increase max_connections or restart idle sessions.',
            'technical_evidence' => 'pg_stat_activity shows 100/100 active connections.',
            'affected_hosts' => ['10001', '10002'],
            'command_execution_results' => [
                [
                    'command' => 'psql -c "SELECT count(*) FROM pg_stat_activity;"',
                    'output' => 'count: 100',
                    'status' => 'success',
                    'host_id' => '10001',
                    'exit_code' => 0
                ]
            ],
            'custom_meta' => 'value123'
        ]);

        $result = ScannerResultParser::parse($json);

        $this->assertInstanceOf(ScannerResult::class, $result);
        $this->assertSame(ScreeningStatus::SUCCESS, $result->status);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('Postgres database connection pool exhaustion on DB master.', $result->rootCause);
        $this->assertSame('Increase max_connections or restart idle sessions.', $result->suggestedNextSteps);
        $this->assertSame('pg_stat_activity shows 100/100 active connections.', $result->technicalEvidence);
        $this->assertSame(['10001', '10002'], $result->affectedHosts);
        $this->assertCount(1, $result->commandExecutionResults);
        $this->assertInstanceOf(CommandExecutionResult::class, $result->commandExecutionResults[0]);
        $this->assertSame('psql -c "SELECT count(*) FROM pg_stat_activity;"', $result->commandExecutionResults[0]->command);
        $this->assertSame(0, $result->commandExecutionResults[0]->exitCode);
        $this->assertSame('value123', $result->metadata['custom_meta']);
    }

    public function testParsesJsonEnclosedInMarkdownCodeBlock(): void
    {
        $raw = "```json\n" . json_encode([
            'status' => '######FAILED:AGENT_ERROR########',
            'root_cause' => 'Zabbix agent unreachable on host srv-app-02.',
            'suggested_next_steps' => 'Verify network firewall and restart zabbix-agent service.',
            'technical_evidence' => 'Connection timed out to 10.0.0.5:10050',
            'affected_hosts' => ['10005']
        ]) . "\n```";

        $result = ScannerResultParser::parse($raw);

        $this->assertSame(ScreeningStatus::FAILED_AGENT_ERROR, $result->status);
        $this->assertFalse($result->isSuccess());
        $this->assertSame('Zabbix agent unreachable on host srv-app-02.', $result->rootCause);
        $this->assertSame(['10005'], $result->affectedHosts);
    }

    public function testValidationFailsWhenStatusIsMissing(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Missing required field "status"');

        ScannerResultParser::parse(json_encode([
            'root_cause' => 'Something failed',
            'suggested_next_steps' => 'Check logs'
        ]));
    }

    public function testValidationFailsWhenStatusIsInvalid(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Invalid status "UNKNOWN_STATUS"');

        ScannerResultParser::parse(json_encode([
            'status' => 'UNKNOWN_STATUS',
            'root_cause' => 'Something failed',
            'suggested_next_steps' => 'Check logs'
        ]));
    }

    public function testValidationFailsWhenRootCauseIsMissing(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Missing required field "root_cause"');

        ScannerResultParser::parse(json_encode([
            'status' => '######SUCCESS########',
            'suggested_next_steps' => 'Check logs'
        ]));
    }

    public function testValidationFailsWhenRootCauseIsEmpty(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Field "root_cause" cannot be empty');

        ScannerResultParser::parse(json_encode([
            'status' => '######SUCCESS########',
            'root_cause' => '   ',
            'suggested_next_steps' => 'Check logs'
        ]));
    }

    public function testValidationFailsWhenSuggestedNextStepsIsMissing(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Missing required field "suggested_next_steps"');

        ScannerResultParser::parse(json_encode([
            'status' => '######SUCCESS########',
            'root_cause' => 'Root cause explained'
        ]));
    }

    public function testValidationFailsWhenAffectedHostsIsNotArray(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Field "affected_hosts" must be an array');

        ScannerResultParser::parse(json_encode([
            'status' => '######SUCCESS########',
            'root_cause' => 'Root cause explained',
            'suggested_next_steps' => 'Check logs',
            'affected_hosts' => 'srv-01'
        ]));
    }

    public function testValidationFailsOnEmptyInput(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Scanner output cannot be empty');

        ScannerResultParser::parse('');
    }

    public function testFormatters(): void
    {
        $result = new ScannerResult(
            status: ScreeningStatus::SUCCESS,
            rootCause: 'Disk full on root partition /.',
            suggestedNextSteps: 'Clean up old log files in /var/log.',
            technicalEvidence: '/dev/sda1 100% 50G/50G',
            affectedHosts: ['10001']
        );

        // JsonFormatter
        $jsonFormatter = new JsonFormatter(pretty: true);
        $jsonOutput = $jsonFormatter->format($result);
        $decoded = json_decode($jsonOutput, true);
        $this->assertIsArray($decoded);
        $this->assertSame('######SUCCESS########', $decoded['status']);
        $this->assertSame('Disk full on root partition /.', $decoded['root_cause']);

        // ConsoleFormatter
        $consoleFormatter = new ConsoleFormatter();
        $consoleOutput = $consoleFormatter->format($result);
        $this->assertStringContainsString("0- STATUS RESULT:\n######SUCCESS########", $consoleOutput);
        $this->assertStringContainsString("1- ROOT-CAUSE ANALYSIS:\nDisk full on root partition /.", $consoleOutput);
        $this->assertStringContainsString("2- SUGGESTED NEXT STEPS:\nClean up old log files in /var/log.", $consoleOutput);
        $this->assertStringContainsString("3- TECHNICAL OUTPUT (RAW):\n/dev/sda1 100% 50G/50G", $consoleOutput);

        // ServiceNowFormatter
        $serviceNowFormatter = new ServiceNowFormatter();
        $snowOutput = $serviceNowFormatter->format($result);
        $this->assertStringStartsWith("AI Agent:\n\n", $snowOutput);
        $this->assertStringContainsString("THIS MESSAGE WAS GENERATED BY AN AUTONOMOUS AI-AGENT", $snowOutput);
    }

    public function testLegacyTextFormatFallback(): void
    {
        $legacyText = "0- STATUS RESULT:\n######SUCCESS########\n\n"
            . "1- ROOT-CAUSE ANALYSIS:\nMemory leak detected in java process.\n\n"
            . "2- SUGGESTED NEXT STEPS:\nRestart the application and capture heap dump.\n\n"
            . "3- TECHNICAL OUTPUT (RAW):\nfree -m shows 10MB free.";

        $scannerResult = ScannerResultParser::parse($legacyText);
        $this->assertSame(ScreeningStatus::SUCCESS, $scannerResult->status);
        $this->assertSame('Memory leak detected in java process.', $scannerResult->rootCause);

        $screeningResult = ScreeningResultParser::parse($legacyText);
        $this->assertSame(ScreeningStatus::SUCCESS, $screeningResult->status);
        $this->assertSame('Memory leak detected in java process.', $screeningResult->rootCauseAnalysis);
    }
}
