<?php

declare(strict_types=1);

namespace AI\Application;

use AI\Application\Daemon\DaemonConfig;
use AI\Application\Daemon\ScreeningDaemon;
use AI\Application\Daemon\ServiceNowIncidentPoller;
use AI\Application\Daemon\TicketProcessor;
use AI\Core\Config\Env;
use AI\Core\Config\MissingConfigurationException;
use AI\Infrastructure\Database\ConnectionFactory;
use AI\Infrastructure\Database\DatabaseBootstrapper;
use AI\Infrastructure\Database\DatabaseConfig;
use AI\Infrastructure\Logging\Logger;
use AI\Infrastructure\Persistence\DaemonRunRepository;
use AI\Infrastructure\Persistence\ScreeningRepository;
use PDO;
use ServiceNOW\Client as ServiceNOW;

/**
 * Composition root: wires infrastructure from the environment. Everything is lazy
 * so commands only pay for what they use (e.g. `migrate` never touches ServiceNOW).
 */
final class Kernel
{
    private ?Logger $logger = null;
    private ?PDO $pdo = null;
    private ?ServiceNOW $serviceNow = null;

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    public function migrationsPath(): string
    {
        return $this->projectRoot . '/database/migrations';
    }

    public function logger(): Logger
    {
        return $this->logger ??= Logger::fromEnvironment('app');
    }

    public function databaseConfig(): DatabaseConfig
    {
        return DatabaseConfig::fromEnvironment();
    }

    public function connectionFactory(): ConnectionFactory
    {
        return new ConnectionFactory($this->databaseConfig());
    }

    /**
     * Ensures the database exists and the schema is migrated, then returns the connection.
     */
    public function database(): PDO
    {
        return $this->pdo ??= (new DatabaseBootstrapper(
            $this->connectionFactory(),
            $this->migrationsPath(),
            $this->logger()->withChannel('db')
        ))->bootstrap();
    }

    public function serviceNow(): ServiceNOW
    {
        if ($this->serviceNow !== null) {
            return $this->serviceNow;
        }

        foreach (['SERVICENOW_INSTANCE_CUSTOM_URL', 'SERVICENOW_INSTANCE_USER', 'SERVICENOW_INSTANCE_PASSWORD'] as $key) {
            if (Env::get($key) === null) {
                throw new MissingConfigurationException("Required environment variable '{$key}' is not set.");
            }
        }

        return $this->serviceNow = new ServiceNOW(
            instance: Env::required('SERVICENOW_INSTANCE_CUSTOM_URL'),
            authentication_type: 'basic',
            authentication_data: [
                'username' => Env::required('SERVICENOW_INSTANCE_USER'),
                'password' => Env::required('SERVICENOW_INSTANCE_PASSWORD'),
            ],
            proxy: (string)Env::get('PROXY', ''),
            secure: true,
            custom_url: true,
            ca_bundle: Env::firstOf(['SERVICENOW_CA_BUNDLE', 'SSL_CA_BUNDLE'])
        );
    }

    public function screeningService(): IncidentScreeningService
    {
        $this->assertAiConfigured();

        return new IncidentScreeningService(serviceNowClient: $this->serviceNow());
    }

    public function daemon(?DaemonConfig $config = null): ScreeningDaemon
    {
        $config ??= DaemonConfig::fromEnvironment();
        $pdo = $this->database();
        $screenings = new ScreeningRepository($pdo);

        $poller = new ServiceNowIncidentPoller(
            $this->serviceNow(),
            $config->pollTable,
            $config->pollQuery,
            $config->pollLimit
        );

        $processor = new TicketProcessor(
            $this->screeningService(),
            $screenings,
            $poller,
            $config,
            $this->logger()->withChannel('screening')
        );

        return new ScreeningDaemon(
            $config,
            $poller,
            $processor,
            $screenings,
            new DaemonRunRepository($pdo),
            $this->logger()->withChannel('daemon')
        );
    }

    private function assertAiConfigured(): void
    {
        $missing = [];
        foreach (['AZURE_OPENAI_KEY', 'AZURE_OPENAI_BASE_URL', 'AZURE_OPENAI_MODEL', 'AZURE_OPENAI_API_VERSION', 'ZABBIX_ENDPOINT', 'ZABBIX_USER', 'ZABBIX_PASSWORD'] as $key) {
            if (Env::get($key) === null) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new MissingConfigurationException('Missing required environment variables: ' . implode(', ', $missing));
        }
    }
}
