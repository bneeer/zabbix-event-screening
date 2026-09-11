<?php

declare(strict_types=1);

namespace AI\Application\Daemon;

use AI\Core\Config\Env;
use AI\Core\Config\MissingConfigurationException;

final readonly class DaemonConfig
{
    public function __construct(
        public string $pollTable,
        public string $pollQuery,
        public int $pollLimit,
        public int $pollInterval,
        public int $maxAttempts,
        public bool $retryFailed,
        public bool $runOnce,
        public bool $writeBack,
        public string $writeBackField,
        public string $outputDirectory,
        public ?string $heartbeatFile,
        public int $staleRunningSeconds = 3600
    ) {
        if (trim($pollQuery) === '') {
            throw new MissingConfigurationException(
                'SERVICENOW_POLL_QUERY must contain a ServiceNOW encoded query (sysparm_query) so the daemon knows which tickets to screen.'
            );
        }
        if ($pollInterval < 1) {
            throw new MissingConfigurationException('DAEMON_POLL_INTERVAL must be >= 1 second.');
        }
        if ($maxAttempts < 1) {
            throw new MissingConfigurationException('DAEMON_MAX_ATTEMPTS must be >= 1.');
        }
    }

    public static function fromEnvironment(): self
    {
        $storage = rtrim((string)Env::get('APP_STORAGE_PATH', dirname(__DIR__, 4) . '/storage'), '/\\');

        return new self(
            pollTable: Env::get('SERVICENOW_POLL_TABLE', 'incident'),
            pollQuery: (string)Env::get('SERVICENOW_POLL_QUERY', ''),
            pollLimit: Env::int('SERVICENOW_POLL_LIMIT', 20),
            pollInterval: Env::int('DAEMON_POLL_INTERVAL', 60),
            maxAttempts: Env::int('DAEMON_MAX_ATTEMPTS', 3),
            retryFailed: Env::bool('DAEMON_RETRY_FAILED', true),
            runOnce: Env::bool('DAEMON_RUN_ONCE', false),
            writeBack: Env::bool('SERVICENOW_WRITE_BACK', false),
            writeBackField: Env::get('SERVICENOW_WRITE_BACK_FIELD', 'work_notes'),
            outputDirectory: Env::get('DAEMON_OUTPUT_DIR', $storage . '/screenings'),
            heartbeatFile: Env::get('DAEMON_HEARTBEAT_FILE', $storage . '/daemon.heartbeat'),
            staleRunningSeconds: Env::int('DAEMON_STALE_RUNNING_SECONDS', 3600)
        );
    }
}
