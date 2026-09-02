<?php

namespace Tests\Unit;

use AI\Core\Security\CommandValidator;
use AI\Core\Tools\Zabbix\ZabbixAdHocRunner;
use PHPUnit\Framework\TestCase;
use Zabbix\Client as ZabbixClient;
use Zabbix\ExecutionType;

class ZabbixAdHocRunnerSecurityTest extends TestCase
{
    public function testDangerousCommandNeverReachesZabbixClient(): void
    {
        // Mock Zabbix Client
        $mockClient = $this->createMock(ZabbixClient::class);

        // createScript and executeScript must NEVER be called
        $mockClient->expects($this->never())->method('createScript');
        $mockClient->expects($this->never())->method('executeScript');
        $mockClient->expects($this->never())->method('deleteScript');

        $runner = new ZabbixAdHocRunner(client: $mockClient);

        $result = $runner(
            hostIds: ['10001'],
            commands: ['rm -rf /var/log'],
            operating_system: "Linux"
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Security boundary rejected command', $result['message']);
        $this->assertSame('rm -rf /var/log', $result['command']);
    }

    public function testChainedCommandNeverReachesZabbixClient(): void
    {
        $mockClient = $this->createMock(ZabbixClient::class);

        $mockClient->expects($this->never())->method('createScript');
        $mockClient->expects($this->never())->method('executeScript');

        $runner = new ZabbixAdHocRunner(client: $mockClient);

        $result = $runner(
            hostIds: ['10001'],
            commands: ['uptime; cat /etc/shadow'],
            operating_system: "Linux"
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Security boundary rejected command', $result['message']);
    }

    public function testValidCommandExecutesWithDefaultAgentAndCleansUp(): void
    {
        $mockClient = $this->createMock(ZabbixClient::class);

        // Expect script creation with ExecutionType::AGENT (0)
        $mockClient->expects($this->once())
            ->method('createScript')
            ->with(
                $this->stringStartsWith('MCIAdHoc_'),
                'uptime',
                0
            )
            ->willReturn(['scriptids' => ['9999']]);

        // Expect execution on target host
        $mockClient->expects($this->once())
            ->method('executeScript')
            ->with('9999', '10001')
            ->willReturn(['response' => 'success', 'value' => 'up 10 days']);

        // Expect guaranteed cleanup / deletion
        $mockClient->expects($this->once())
            ->method('deleteScript')
            ->with('9999')
            ->willReturn(true);

        $runner = new ZabbixAdHocRunner(client: $mockClient);

        $result = $runner(
            hostIds: ['10001'],
            commands: ['uptime'],
            operating_system: "Linux"
        );

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('10001', $result['results']);
        $this->assertStringContainsString('up 10 days', $result['results']['10001']['value']);
    }

    public function testAiCannotOverrideConfiguredExecutionType(): void
    {
        $mockClient = $this->createMock(ZabbixClient::class);

        // Even though caller/AI supplies execution_type = "2" (SSH), the configured type (AGENT = 0) is enforced!
        $mockClient->expects($this->once())
            ->method('createScript')
            ->with(
                $this->stringStartsWith('MCIAdHoc_'),
                'uptime',
                0 // Enforces configured ExecutionType::AGENT (0)
            )
            ->willReturn(['scriptids' => ['8888']]);

        $mockClient->expects($this->once())
            ->method('executeScript')
            ->with('8888', '10001')
            ->willReturn(['response' => 'success', 'value' => 'output']);

        $mockClient->expects($this->once())
            ->method('deleteScript')
            ->with('8888')
            ->willReturn(true);

        $runner = new ZabbixAdHocRunner(client: $mockClient, executionType: ExecutionType::AGENT);

        $result = $runner(
            hostIds: ['10001'],
            commands: ['uptime'],
            execution_type: "2", // Attempt by AI to force SSH
            operating_system: "Linux"
        );

        $this->assertSame('success', $result['status']);
    }

    public function testConfiguredSshExecutionTypeWorks(): void
    {
        $mockClient = $this->createMock(ZabbixClient::class);

        // Expect script creation with SSH (2)
        $mockClient->expects($this->once())
            ->method('createScript')
            ->with(
                $this->stringStartsWith('MCIAdHoc_'),
                'uptime',
                2 // ExecutionType::SSH
            )
            ->willReturn(['scriptids' => ['7777']]);

        $mockClient->expects($this->once())
            ->method('executeScript')
            ->with('7777', '10001')
            ->willReturn(['response' => 'success', 'value' => 'ssh output']);

        $mockClient->expects($this->once())
            ->method('deleteScript')
            ->with('7777')
            ->willReturn(true);

        $runner = new ZabbixAdHocRunner(client: $mockClient, executionType: ExecutionType::SSH);

        $result = $runner(
            hostIds: ['10001'],
            commands: ['uptime'],
            operating_system: "Linux"
        );

        $this->assertSame('success', $result['status']);
    }

    public function testConfiguredTelnetExecutionTypeWorks(): void
    {
        $mockClient = $this->createMock(ZabbixClient::class);

        // Expect script creation with Telnet (3)
        $mockClient->expects($this->once())
            ->method('createScript')
            ->with(
                $this->stringStartsWith('MCIAdHoc_'),
                'uptime',
                3 // ExecutionType::TELNET
            )
            ->willReturn(['scriptids' => ['6666']]);

        $mockClient->expects($this->once())
            ->method('executeScript')
            ->with('6666', '10001')
            ->willReturn(['response' => 'success', 'value' => 'telnet output']);

        $mockClient->expects($this->once())
            ->method('deleteScript')
            ->with('6666')
            ->willReturn(true);

        $runner = new ZabbixAdHocRunner(client: $mockClient, executionType: ExecutionType::TELNET);

        $result = $runner(
            hostIds: ['10001'],
            commands: ['uptime'],
            operating_system: "Linux"
        );

        $this->assertSame('success', $result['status']);
    }

    public function testUnsupportedExecutionTypeRejectedSafely(): void
    {
        $mockClient = $this->createMock(ZabbixClient::class);
        $mockClient->expects($this->never())->method('createScript');

        // IPMI (4) is a valid Zabbix type enum but unsupported for diagnostic runner
        $runner = new ZabbixAdHocRunner(client: $mockClient, executionType: ExecutionType::IPMI);

        $result = $runner(
            hostIds: ['10001'],
            commands: ['uptime'],
            operating_system: "Linux"
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Invalid or unsupported configured execution_type', $result['message']);
    }

    public function testInvalidExecutionTypeConfigurationRejectedSafely(): void
    {
        $mockClient = $this->createMock(ZabbixClient::class);
        $mockClient->expects($this->never())->method('createScript');

        $runner = new ZabbixAdHocRunner(client: $mockClient, executionType: '99');

        $result = $runner(
            hostIds: ['10001'],
            commands: ['uptime'],
            operating_system: "Linux"
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Invalid or unsupported configured execution_type', $result['message']);
    }

    public function testMultipleSafeCommandsExecuteIndividually(): void
    {
        $mockClient = $this->createMock(ZabbixClient::class);

        $createdScripts = [];
        $mockClient->expects($this->exactly(2))
            ->method('createScript')
            ->willReturnCallback(function (string $name, string $command, int $type) use (&$createdScripts) {
                $scriptId = (string) (100 + count($createdScripts));
                $createdScripts[] = ['id' => $scriptId, 'command' => $command];
                return ['scriptids' => [$scriptId]];
            });

        $mockClient->expects($this->exactly(2))
            ->method('executeScript')
            ->willReturnCallback(function (string $scriptId, string $hostId) {
                return ['response' => 'success', 'value' => "output for script {$scriptId} on host {$hostId}"];
            });

        $deletedScripts = [];
        $mockClient->expects($this->exactly(2))
            ->method('deleteScript')
            ->willReturnCallback(function (string $scriptId) use (&$deletedScripts) {
                $deletedScripts[] = $scriptId;
                return true;
            });

        $runner = new ZabbixAdHocRunner(client: $mockClient);

        $result = $runner(
            hostIds: ['10001'],
            commands: ['uptime', 'df -h'],
            operating_system: "Linux"
        );

        $this->assertSame('success', $result['status']);
        $this->assertCount(2, $createdScripts);
        $this->assertSame('uptime', $createdScripts[0]['command']);
        $this->assertSame('df -h', $createdScripts[1]['command']);
        $this->assertSame(['100', '101'], $deletedScripts);

        $this->assertStringContainsString('Command: uptime', $result['results']['10001']['value']);
        $this->assertStringContainsString('Command: df -h', $result['results']['10001']['value']);
    }

    public function testOneInvalidCommandInBatchRejectsEntireBatchWithoutExecution(): void
    {
        $mockClient = $this->createMock(ZabbixClient::class);

        // Crucial: absolutely no script should be created or executed if any command in the batch fails validation!
        $mockClient->expects($this->never())->method('createScript');
        $mockClient->expects($this->never())->method('executeScript');
        $mockClient->expects($this->never())->method('deleteScript');

        $runner = new ZabbixAdHocRunner(client: $mockClient);

        $result = $runner(
            hostIds: ['10001'],
            commands: ['uptime', 'rm -rf /tmp', 'df -h'],
            operating_system: "Linux"
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Security boundary rejected command', $result['message']);
        $this->assertSame('rm -rf /tmp', $result['command']);
    }

    public function testCommandChainingCannotBeIntroducedThroughGeneratedInput(): void
    {
        $mockClient = $this->createMock(ZabbixClient::class);
        $mockClient->expects($this->never())->method('createScript');
        $mockClient->expects($this->never())->method('executeScript');

        $runner = new ZabbixAdHocRunner(client: $mockClient);

        $chainingAttempts = [
            'uptime && whoami',
            'uptime || whoami',
            'uptime ; whoami',
            'uptime & whoami',
            'uptime | grep root',
            'uptime > /tmp/out',
            'uptime >> /tmp/out',
            'uptime < /etc/passwd',
            'uptime $(whoami)',
            'uptime `whoami`',
            "uptime\nwhoami"
        ];

        foreach ($chainingAttempts as $cmd) {
            $result = $runner(
                hostIds: ['10001'],
                commands: [$cmd],
                operating_system: "Linux"
            );

            $this->assertSame('error', $result['status'], "Failed to reject chaining attempt: {$cmd}");
            $this->assertStringContainsString('Security boundary rejected command', $result['message']);
        }
    }

    public function testShellOperatorsCannotAppearIndirectlyThroughMultipleCommands(): void
    {
        $mockClient = $this->createMock(ZabbixClient::class);
        $mockClient->expects($this->never())->method('createScript');
        $mockClient->expects($this->never())->method('executeScript');

        $runner = new ZabbixAdHocRunner(client: $mockClient);

        // Attempting to split a shell injection across elements in the commands array
        $result = $runner(
            hostIds: ['10001'],
            commands: [
                'uptime',
                '; echo hacked',
                'df -h'
            ],
            operating_system: "Linux"
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Security boundary rejected command', $result['message']);
    }
}
