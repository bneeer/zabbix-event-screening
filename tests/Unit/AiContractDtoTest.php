<?php

namespace Tests\Unit;

use AI\Core\DTO\DiagnosticCommand;
use AI\Core\DTO\DiagnosticPlan;
use AI\Core\DTO\ExecutionResult;
use AI\Core\DTO\HostExecutionResult;
use AI\Core\DTO\Parser\DiagnosticPlanParser;
use AI\Core\DTO\Parser\ExecutionResultParser;
use AI\Core\DTO\Parser\HostFinderResultParser;
use AI\Core\DTO\Parser\JsonHelper;
use AI\Core\DTO\Parser\ScreeningResultParser;
use AI\Core\DTO\ScreeningResult;
use AI\Core\DTO\ScreeningStatus;
use AI\Core\DTO\ZabbixHost;
use AI\Core\DTO\ZabbixHostMatchResult;
use AI\Core\Exceptions\AiContractValidationException;
use PHPUnit\Framework\TestCase;

class AiContractDtoTest extends TestCase
{
    // --- DiagnosticCommand Tests ---

    public function testDiagnosticCommandValid(): void
    {
        $cmd = new DiagnosticCommand('  uptime  ');
        $this->assertSame('uptime', $cmd->command);
        $this->assertSame('uptime', (string)$cmd);
        $this->assertSame('uptime', $cmd->jsonSerialize());
    }

    public function testDiagnosticCommandEmptyThrowsException(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Diagnostic command cannot be empty.');
        new DiagnosticCommand('   ');
    }

    // --- DiagnosticPlan & Parser Tests ---

    public function testDiagnosticPlanValidInstantiationAndSerialization(): void
    {
        $plan = new DiagnosticPlan(
            customer: 'Acme Corp',
            analysis: 'Checking server load',
            commands: [
                new DiagnosticCommand('uptime'),
                new DiagnosticCommand('df -h'),
            ],
            hostIds: ['10001' => 'Linux']
        );

        $this->assertSame('Acme Corp', $plan->customer);
        $this->assertSame('Checking server load', $plan->analysis);
        $this->assertCount(2, $plan->commands);
        $this->assertSame(['uptime', 'df -h'], $plan->getCommandStrings());
        $this->assertSame(['10001' => 'Linux'], $plan->hostIds);

        $serialized = $plan->jsonSerialize();
        $this->assertSame('Acme Corp', $serialized['customer']);
        $this->assertSame(['uptime', 'df -h'], $serialized['commands']);
        $this->assertSame(['10001' => 'Linux'], $serialized['hostIds']);
    }

    public function testDiagnosticPlanParserValidJsonString(): void
    {
        $raw = <<<JSON
{
    "customer": "Customer A",
    "analysis": "High CPU usage triage",
    "commands": [
        "uptime",
        "top -b -n 1"
    ]
}
JSON;

        $plan = DiagnosticPlanParser::parse($raw);
        $this->assertSame('Customer A', $plan->customer);
        $this->assertSame('High CPU usage triage', $plan->analysis);
        $this->assertSame(['uptime', 'top -b -n 1'], $plan->getCommandStrings());
        $this->assertEmpty($plan->hostIds);

        $withHosts = $plan->withHostIds(['10101' => 'Linux']);
        $this->assertSame(['10101' => 'Linux'], $withHosts->hostIds);
    }

    public function testDiagnosticPlanParserWithMarkdownCodeBlock(): void
    {
        $raw = "```json\n" . json_encode([
            'customer' => 'Customer B',
            'analysis' => 'Disk check',
            'commands' => ['df -h'],
        ]) . "\n```";

        $plan = DiagnosticPlanParser::parse($raw);
        $this->assertSame('Customer B', $plan->customer);
        $this->assertSame(['df -h'], $plan->getCommandStrings());
    }

    public function testDiagnosticPlanParserMissingCustomer(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Missing required field "customer" in diagnostic plan.');

        DiagnosticPlanParser::parse([
            'analysis' => 'Test',
            'commands' => ['uptime']
        ]);
    }

    public function testDiagnosticPlanParserCustomerWrongType(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Field "customer" must be a string, got array.');

        DiagnosticPlanParser::parse([
            'customer' => ['invalid'],
            'analysis' => 'Test',
            'commands' => ['uptime']
        ]);
    }

    public function testDiagnosticPlanParserMissingAnalysis(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Missing required field "analysis" in diagnostic plan.');

        DiagnosticPlanParser::parse([
            'customer' => 'Test Corp',
            'commands' => ['uptime']
        ]);
    }

    public function testDiagnosticPlanParserAnalysisWrongType(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Field "analysis" must be a string, got integer.');

        DiagnosticPlanParser::parse([
            'customer' => 'Test Corp',
            'analysis' => 12345,
            'commands' => ['uptime']
        ]);
    }

    public function testDiagnosticPlanParserMissingCommands(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Missing required field "commands" in diagnostic plan.');

        DiagnosticPlanParser::parse([
            'customer' => 'Test Corp',
            'analysis' => 'Test analysis'
        ]);
    }

    public function testDiagnosticPlanParserCommandsNotAnArray(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Field "commands" must be an array, got string.');

        DiagnosticPlanParser::parse([
            'customer' => 'Test Corp',
            'analysis' => 'Test analysis',
            'commands' => 'uptime'
        ]);
    }

    public function testDiagnosticPlanParserEmptyCommands(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Field "commands" cannot be empty in diagnostic plan.');

        DiagnosticPlanParser::parse([
            'customer' => 'Test Corp',
            'analysis' => 'Test analysis',
            'commands' => []
        ]);
    }

    public function testDiagnosticPlanParserCommandItemNotString(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Each command in "commands" must be a string, got integer at index 1.');

        DiagnosticPlanParser::parse([
            'customer' => 'Test Corp',
            'analysis' => 'Test analysis',
            'commands' => ['uptime', 123]
        ]);
    }

    public function testDiagnosticPlanParserStrictRejectsUnexpectedExtraFields(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Unexpected extra fields in DiagnosticPlan payload: extra_unwanted_key');

        DiagnosticPlanParser::parse([
            'customer' => 'Test Corp',
            'analysis' => 'Test analysis',
            'commands' => ['uptime'],
            'extra_unwanted_key' => 'malicious or unexpected'
        ], strict: true);
    }

    public function testDiagnosticPlanParserMalformedJson(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Malformed JSON received from AI agent');

        DiagnosticPlanParser::parse('{ invalid json string ...');
    }

    // --- ExecutionResult & Parser Tests ---

    public function testExecutionResultParserValid(): void
    {
        $raw = [
            'status' => 'success',
            'message' => 'Completed',
            'results' => [
                '10001' => [
                    'status' => 'success',
                    'value' => 'up 10 days',
                    'raw' => ['exit' => 0]
                ]
            ]
        ];

        $result = ExecutionResultParser::parse($raw);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('success', $result->status);
        $this->assertSame('Completed', $result->message);

        $hostResult = $result->getHostResult('10001');
        $this->assertInstanceOf(HostExecutionResult::class, $hostResult);
        $this->assertSame('10001', $hostResult->hostId);
        $this->assertTrue($hostResult->isSuccess());
        $this->assertSame('up 10 days', $hostResult->value);
        $this->assertSame(['exit' => 0], $hostResult->raw);
    }

    public function testExecutionResultParserMissingStatus(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Missing required field "status" in execution result.');

        ExecutionResultParser::parse(['message' => 'No status']);
    }

    public function testExecutionResultParserInvalidStatusType(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Field "status" must be a string, got boolean.');

        ExecutionResultParser::parse(['status' => false]);
    }

    public function testExecutionResultParserInvalidResultsType(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Field "results" must be an array/map of host results, got string.');

        ExecutionResultParser::parse([
            'status' => 'success',
            'results' => 'not an array'
        ]);
    }

    // --- ScreeningResult & Parser Tests ---

    public function testScreeningResultParserValid(): void
    {
        $rawReport = <<<TEXT
0- STATUS RESULT:
######SUCCESS########

1- ROOT-CAUSE ANALYSIS:
High memory usage caused by rogue background job.

2- SUGGESTED NEXT STEPS:
Restart worker queue or allocate more memory.

3- TECHNICAL OUTPUT (RAW):
Host 10001:
free -m: 95% memory used
TEXT;

        $screening = ScreeningResultParser::parse($rawReport);
        $this->assertSame(ScreeningStatus::SUCCESS, $screening->status);
        $this->assertTrue($screening->isSuccess());
        $this->assertSame('High memory usage caused by rogue background job.', $screening->rootCauseAnalysis);
        $this->assertSame('Restart worker queue or allocate more memory.', $screening->suggestedNextSteps);
        $this->assertStringContainsString('free -m: 95% memory used', $screening->rawTechnicalOutput);
    }

    public function testScreeningResultParserFailedStatus(): void
    {
        $rawReport = <<<TEXT
0- STATUS RESULT:
######FAILED:AGENT_ERROR########

1- ROOT-CAUSE ANALYSIS:
Unable to contact Zabbix agent on target host.

2- SUGGESTED NEXT STEPS:
Verify network route and agent service status.

3- TECHNICAL OUTPUT (RAW):
Agent connection timeout
TEXT;

        $screening = ScreeningResultParser::parse($rawReport);
        $this->assertSame(ScreeningStatus::FAILED_AGENT_ERROR, $screening->status);
        $this->assertFalse($screening->isSuccess());
    }

    public function testScreeningResultParserMissingStatusSection(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Missing "0- STATUS RESULT:" section in screening output.');

        ScreeningResultParser::parse("Random text without required format");
    }

    public function testScreeningResultParserInvalidStatusValue(): void
    {
        $rawReport = <<<TEXT
0- STATUS RESULT:
UNKNOWN_STATUS_CODE

1- ROOT-CAUSE ANALYSIS:
Something went wrong.

2- SUGGESTED NEXT STEPS:
Investigate.
TEXT;

        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Invalid status "UNKNOWN_STATUS_CODE" in "0- STATUS RESULT:" section.');

        ScreeningResultParser::parse($rawReport);
    }

    public function testScreeningResultParserMissingRootCauseSection(): void
    {
        $rawReport = <<<TEXT
0- STATUS RESULT:
######SUCCESS########

2- SUGGESTED NEXT STEPS:
Next steps without root cause.
TEXT;

        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Missing "1- ROOT-CAUSE ANALYSIS:" section in screening output.');

        ScreeningResultParser::parse($rawReport);
    }

    // --- HostFinderResultParser Tests ---

    public function testHostFinderResultParserValid(): void
    {
        $raw = json_encode([
            'hostnames' => ['srv-web-01'],
            'hosts' => [
                [
                    'hostid' => '10042',
                    'host' => 'srv-web-01',
                    'name' => 'Web Production Server',
                    'operating_system' => 'Linux'
                ]
            ]
        ]);

        $matchResult = HostFinderResultParser::parse($raw);
        $this->assertFalse($matchResult->isEmpty());
        $this->assertSame(['srv-web-01'], $matchResult->hostnames);
        $this->assertCount(1, $matchResult->hosts);
        $this->assertSame(['10042' => 'Linux'], $matchResult->getHostIdToOsMap());
    }

    public function testHostFinderResultParserEmptyHosts(): void
    {
        $matchResult = HostFinderResultParser::parse('{"hostnames":[],"hosts":[]}');
        $this->assertTrue($matchResult->isEmpty());
        $this->assertEmpty($matchResult->getHostIdToOsMap());
    }

    public function testHostFinderResultParserInvalidHostId(): void
    {
        $this->expectException(AiContractValidationException::class);
        $this->expectExceptionMessage('Missing or invalid "hostid" for host at index 0.');

        HostFinderResultParser::parse([
            'hosts' => [
                ['host' => 'test-host']
            ]
        ]);
    }
}
