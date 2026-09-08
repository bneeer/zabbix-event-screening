<?php

namespace Tests\Unit;

use AI\Core\Errors\ZabbixErrorCode;
use AI\Core\Security\CommandValidator;
use AI\Core\Tools\Itsm\GetServiceNowIncidentTool;
use AI\Core\Tools\Zabbix\ZabbixAdHocRunner;
use AI\Core\Tools\Zabbix\ZabbixHostFinder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Zabbix\Client as ZabbixClient;
use Zabbix\ExecutionType;

class ToolErrorHardeningTest extends TestCase
{
    private string $originalErrorLog;
    private array $loggedMessages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loggedMessages = [];

        // Capture error_log output during tests
        set_error_handler(function ($errno, $errstr) {
            $this->loggedMessages[] = $errstr;
            return true;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        parent::tearDown();
    }

    public function testZabbixAdHocRunnerNeverExposesStackTraceOnClientException(): void
    {
        $mockClient = $this->createMock(ZabbixClient::class);
        $mockClient->method('createScript')
            ->willThrowException(new \RuntimeException('Sensitive DB connection to 10.0.0.5:3306 failed with secret=pass123 in /var/www/secret/path.php:42'));

        $runner = new ZabbixAdHocRunner(
            client: $mockClient,
            validator: new CommandValidator(),
            executionType: ExecutionType::AGENT
        );

        $result = $runner->__invoke(
            hostIds: ['1001'],
            commands: ['uptime']
        );

        $this->assertEquals('error', $result['status']);
        $this->assertEquals(ZabbixErrorCode::UNKNOWN_ERROR->value, $result['error_code']);
        $this->assertNotEmpty($result['correlation_id']);
        $this->assertArrayNotHasKey('trace', $result);
        $this->assertArrayNotHasKey('details', $result);
        $this->assertStringNotContainsString('trace', json_encode($result));
        $this->assertStringNotContainsString('/var/www/secret', json_encode($result));
        $this->assertStringNotContainsString('pass123', json_encode($result));
        $this->assertStringNotContainsString('10.0.0.5', json_encode($result));
    }

    public function testZabbixAdHocRunnerNeverExposesStackTraceOnScriptExecutionException(): void
    {
        $mockClient = $this->createMock(ZabbixClient::class);
        $mockClient->method('createScript')
            ->willReturn(['scriptids' => ['42']]);
        $mockClient->method('executeScript')
            ->willThrowException(new \Exception('Internal connection timed out to 192.168.1.100 with token=abc_secret'));
        $mockClient->method('deleteScript')
            ->willReturn(true);

        $runner = new ZabbixAdHocRunner(
            client: $mockClient,
            validator: new CommandValidator(),
            executionType: ExecutionType::AGENT
        );

        $result = $runner->__invoke(
            hostIds: ['1001'],
            commands: ['uptime']
        );

        $this->assertEquals('partial_failure', $result['status']);
        $this->assertArrayHasKey('1001', $result['results']);
        $hostResult = $result['results']['1001'];
        $this->assertEquals('error', $hostResult['status']);
        $this->assertEquals(ZabbixErrorCode::SCRIPT_EXECUTION_FAILED->value, $hostResult['error_code']);
        $this->assertArrayNotHasKey('trace', $hostResult);
        $this->assertStringNotContainsString('abc_secret', json_encode($result));
        $this->assertStringNotContainsString('192.168.1.100', json_encode($result));
    }

    public function testZabbixAdHocRunnerSanitizesInternalLogMessages(): void
    {
        $mockClient = $this->createMock(ZabbixClient::class);
        $mockClient->method('createScript')
            ->willThrowException(new \RuntimeException('Failed with password=supersecretpass and token=eyJhbGciOi123456'));

        $runner = new ZabbixAdHocRunner(
            client: $mockClient,
            validator: new CommandValidator(),
            executionType: ExecutionType::AGENT
        );

        $runner->__invoke(
            hostIds: ['1001'],
            commands: ['uptime']
        );

        $resultJson = json_encode($runner);
        $this->assertStringNotContainsString('supersecretpass', $resultJson);
    }

    public function testZabbixHostFinderNeverExposesStackTraceOnError(): void
    {
        $finder = new class extends ZabbixHostFinder {
            public ?ZabbixClient $injectedClient = null;

            protected function getClient(): ZabbixClient
            {
                if ($this->injectedClient !== null) {
                    return $this->injectedClient;
                }
                return parent::getClient();
            }
        };

        $mockClient = $this->createMock(ZabbixClient::class);
        $mockClient->method('getAccessToken')
            ->willThrowException(new \RuntimeException('Failed to authenticate with user=admin and pass=secret123 in /opt/zabbix/lib.php'));

        $finder->injectedClient = $mockClient;

        $result = $finder->__invoke(['host1']);

        $this->assertEquals('error', $result['status']);
        $this->assertEquals('ZBX_HOST_LOOKUP_FAILED', $result['error_code']);
        $this->assertNotEmpty($result['correlation_id']);
        $this->assertArrayNotHasKey('trace', $result);
        $this->assertStringNotContainsString('/opt/zabbix', json_encode($result));
        $this->assertStringNotContainsString('secret123', json_encode($result));
    }

    public function testGetServiceNowIncidentToolNeverExposesStackTraceOrSecretsOnError(): void
    {
        $mockHandler = new MockHandler([
            new \GuzzleHttp\Exception\RequestException(
                'Connection error to https://secret-instance.service-now.com with auth Basic dXNlcjpwYXNz',
                new \GuzzleHttp\Psr7\Request('GET', 'test')
            )
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $guzzleClient = new GuzzleClient(['handler' => $handlerStack]);

        $tool = new class($guzzleClient) extends GetServiceNowIncidentTool {
            public function __construct(GuzzleClient $client)
            {
                parent::__construct();
                $this->client = $client;
            }
        };

        $resultJson = $tool->__invoke('INC09735410');
        $result = json_decode($resultJson, true);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertEquals('SNOW_RECORD_FETCH_FAILED', $result['error_code']);
        $this->assertNotEmpty($result['correlation_id']);
        $this->assertArrayNotHasKey('trace', $result);
        $this->assertStringNotContainsString('Basic dXNlcjpwYXNz', $resultJson);
        $this->assertStringNotContainsString('secret-instance', $resultJson);
    }
}
