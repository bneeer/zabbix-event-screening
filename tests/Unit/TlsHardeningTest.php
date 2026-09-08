<?php

namespace Tests\Unit;

use AI\Core\Tools\Itsm\GetServiceNowIncidentTool;
use GuzzleHttp\Client as GuzzleClient;
use NeuronAI\HttpClient\GuzzleHttpClient;
use PHPUnit\Framework\TestCase;
use ServiceNOW\Client as ServiceNowClient;
use Zabbix\Client as ZabbixClient;

class TlsHardeningTest extends TestCase
{
    private ?string $tempCaFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempCaFile = tempnam(sys_get_temp_dir(), 'test_ca_');
        file_put_contents($this->tempCaFile, "-----BEGIN CERTIFICATE-----\nMIIB...\n-----END CERTIFICATE-----\n");
    }

    protected function tearDown(): void
    {
        if ($this->tempCaFile !== null && file_exists($this->tempCaFile)) {
            @unlink($this->tempCaFile);
        }
        parent::tearDown();
    }

    public function testServiceNowClientDefaultsToSecureTls(): void
    {
        $client = new ServiceNowClient(
            instance: 'test-instance',
            authentication_type: 'basic',
            authentication_data: ['username' => 'testuser', 'password' => 'testpass'],
            proxy: ''
        );

        $this->assertTrue($client->isSecure());
        $this->assertNull($client->getCaBundle());
    }

    public function testServiceNowClientAcceptsValidCustomCaBundle(): void
    {
        $client = new ServiceNowClient(
            instance: 'test-instance',
            authentication_type: 'basic',
            authentication_data: ['username' => 'testuser', 'password' => 'testpass'],
            proxy: '',
            secure: true,
            custom_url: false,
            ca_bundle: $this->tempCaFile
        );

        $this->assertTrue($client->isSecure());
        $this->assertSame($this->tempCaFile, $client->getCaBundle());
    }

    public function testServiceNowClientRejectsNonExistentCaBundle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ServiceNOW CA bundle file not found');

        new ServiceNowClient(
            instance: 'test-instance',
            authentication_type: 'basic',
            authentication_data: ['username' => 'testuser', 'password' => 'testpass'],
            proxy: '',
            secure: true,
            custom_url: false,
            ca_bundle: '/path/to/non_existent_ca_bundle.pem'
        );
    }

    public function testZabbixClientPropertyDefaultsToSecure(): void
    {
        $reflector = new \ReflectionClass(ZabbixClient::class);
        $secureProperty = $reflector->getProperty('secure');

        $this->assertTrue($secureProperty->getDefaultValue());
    }

    public function testZabbixClientRejectsNonExistentCaBundle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Zabbix CA bundle file not found');

        new ZabbixClient(
            api_endpoint: 'https://zabbix.example.com/api_jsonrpc.php',
            username: 'user',
            password: 'password',
            version: 6,
            secure: true,
            ca_bundle: '/path/to/non_existent_zabbix_ca.pem'
        );
    }

    public function testGetServiceNowIncidentToolClientEnablesVerification(): void
    {
        $tool = new class extends GetServiceNowIncidentTool {
            public function exposeClient(): GuzzleClient
            {
                return $this->getClient();
            }
        };

        $client = $tool->exposeClient();
        $verifyOption = $client->getConfig('verify');

        $this->assertTrue($verifyOption === true || (is_string($verifyOption) && file_exists($verifyOption)));
        $this->assertNotFalse($verifyOption);
    }

    public function testGuzzleHttpClientDefaultsToSecureVerification(): void
    {
        $guzzleClient = new GuzzleHttpClient();

        $reflector = new \ReflectionClass($guzzleClient);
        $method = $reflector->getMethod('createClient');
        $method->setAccessible(true);

        /** @var GuzzleClient $client */
        $client = $method->invoke($guzzleClient);
        $verify = $client->getConfig('verify');

        $this->assertTrue($verify === true);
    }

    public function testGuzzleHttpClientWithCustomVerify(): void
    {
        $guzzleClient = (new GuzzleHttpClient())->withVerify($this->tempCaFile);

        $reflector = new \ReflectionClass($guzzleClient);
        $method = $reflector->getMethod('createClient');
        $method->setAccessible(true);

        /** @var GuzzleClient $client */
        $client = $method->invoke($guzzleClient);
        $verify = $client->getConfig('verify');

        $this->assertSame($this->tempCaFile, $verify);

        // Test immutability and preservation of verify option through helper methods
        $withBaseUri = $guzzleClient->withBaseUri('https://api.example.com');
        $reflectorBase = new \ReflectionClass($withBaseUri);
        $methodBase = $reflectorBase->getMethod('createClient');
        $methodBase->setAccessible(true);
        /** @var GuzzleClient $clientBase */
        $clientBase = $methodBase->invoke($withBaseUri);
        $this->assertSame($this->tempCaFile, $clientBase->getConfig('verify'));
    }

    public function testComposerHasNoInsecureTlsOverrides(): void
    {
        $composerJsonPath = __DIR__ . '/../../composer.json';
        $this->assertFileExists($composerJsonPath);

        $composerContent = file_get_contents($composerJsonPath);
        $this->assertStringNotContainsString('verify_peer": false', $composerContent);
        $this->assertStringNotContainsString('verify_peer":false', $composerContent);
        $this->assertStringNotContainsString('allow_self_signed": true', $composerContent);
        $this->assertStringNotContainsString('allow_self_signed":true', $composerContent);
    }
}
