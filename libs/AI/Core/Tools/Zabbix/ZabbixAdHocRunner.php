<?php

namespace AI\Core\Tools\Zabbix;

use AI\Core\Errors\ZabbixErrorCode;
use AI\Core\Security\CommandValidationException;
use AI\Core\Security\CommandValidator;
use AI\Core\Security\OperatingSystem;
use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Zabbix\Client as ZabbixClient;
use Zabbix\ExecutionType;

class ZabbixAdHocRunner extends Tool
{
    protected ?ZabbixClient $client = null;

    protected CommandValidator $validator;

    protected ExecutionType|int|string|null $executionType = null;

    public function __construct(
        ?ZabbixClient $client = null,
        ?CommandValidator $validator = null,
        ExecutionType|int|string|null $executionType = null
    ) {
        parent::__construct(
            'run_zabbix_command',
            'Running a command via Zabbix on target hosts',
        );

        $this->client = $client;
        $this->validator = $validator ?? new CommandValidator();
        $this->executionType = $executionType;
    }

    /**
     * Resolves the configured execution channel.
     */
    public function resolveExecutionType(): ?ExecutionType
    {
        $typeToResolve = $this->executionType;

        if ($typeToResolve === null) {
            if (defined('ZABBIX_EXECUTION_TYPE')) {
                $typeToResolve = ZABBIX_EXECUTION_TYPE;
            } elseif (isset($_ENV['ZABBIX_EXECUTION_TYPE'])) {
                $typeToResolve = $_ENV['ZABBIX_EXECUTION_TYPE'];
            } else {
                $typeToResolve = ExecutionType::AGENT;
            }
        }

        return ExecutionType::tryFromValue($typeToResolve);
    }

    /**
     * Set or override the configured execution type at the infrastructure level.
     */
    public function setExecutionType(
        ExecutionType|int|string|null $executionType
    ): self {
        $this->executionType = $executionType;

        return $this;
    }

    /**
     * Return the list of properties exposed to the AI agent.
     *
     * execution_type is intentionally not exposed to the AI.
     */
    protected function properties(): array
    {
        try {
            return [
                new ArrayProperty(
                    name: 'hostIds',
                    description: 'The list of target Zabbix host IDs to run the command',
                    required: true,
                    items: new ToolProperty(
                        name: 'hostid',
                        type: PropertyType::STRING,
                        description: 'The Zabbix host ID',
                        required: true
                    )
                ),
                new ArrayProperty(
                    name: 'commands',
                    description: 'The list of commands to run',
                    required: true,
                    items: new ToolProperty(
                        name: 'command',
                        type: PropertyType::STRING,
                        description: 'The command to run',
                        required: true
                    )
                ),
                new ToolProperty(
                    name: 'operating_system',
                    type: PropertyType::STRING,
                    description: 'Target operating system (Linux or Windows)',
                    required: false
                )
            ];
        } catch (ArrayPropertyException) {
            error_log(
                '[ZabbixAdHocRunner] Failed to define tool properties.'
            );

            return [];
        }
    }

    /**
     * Execute validated diagnostic commands on target hosts.
     *
     * The $execution_type parameter is intentionally ignored and exists
     * only for backwards compatibility with the previous tool signature.
     */
    public function __invoke(
        array $hostIds,
        array $commands,
        mixed $execution_type = null,
        ?string $operating_system = null
    ): array {
        $correlationId = $this->generateCorrelationId();

        try {
            if (empty($commands)) {
                return $this->buildErrorResponse(
                    ZabbixErrorCode::INVALID_INPUT,
                    'No commands were provided for execution.',
                    $correlationId
                );
            }

            if (empty($hostIds)) {
                return $this->buildErrorResponse(
                    ZabbixErrorCode::INVALID_INPUT,
                    'No target hosts were provided for execution.',
                    $correlationId
                );
            }

            $resolvedExecutionType = $this->resolveExecutionType();

            if (
                $resolvedExecutionType === null ||
                !$resolvedExecutionType->isSupported()
            ) {
                $this->logEvent(
                    'Invalid or unsupported configured execution type.',
                    $correlationId
                );

                return $this->buildErrorResponse(
                    ZabbixErrorCode::INVALID_EXECUTION_TYPE,
                    'The configured Zabbix execution type is invalid or unsupported.',
                    $correlationId
                );
            }

            $targetOs = OperatingSystem::fromString($operating_system);

            $validatedCommands = [];

            foreach ($commands as $cmd) {
                try {
                    if ($targetOs !== OperatingSystem::UNKNOWN) {
                        $validatedCommands[] = $this->validator->validate(
                            $cmd,
                            $targetOs
                        );
                    } else {
                        $validatedCommands[] = $this->validator->validateCrossPlatform(
                            $cmd
                        );
                    }
                } catch (CommandValidationException $e) {
                    $this->logSecurityViolation(
                        $e,
                        $correlationId
                    );

                    return $this->buildErrorResponse(
                        ZabbixErrorCode::COMMAND_VALIDATION_FAILED,
                        'One or more commands were rejected by the security policy.',
                        $correlationId
                    );
                }
            }

            $client = $this->getClient();

            $results = [];

            foreach ($hostIds as $hostId) {
                $results[(string) $hostId] = [
                    'status' => 'success',
                    'value' => ''
                ];
            }

            foreach ($validatedCommands as $parsedCommand) {
                $command = $parsedCommand->getRaw();

                $scriptName = sprintf(
                    'MCIAdHoc_%s',
                    hash(
                        'sha256',
                        $correlationId . '|' . $command . '|' . microtime(true)
                    )
                );

                $scriptId = null;

                try {
                    $createResult = $client->createScript(
                        $scriptName,
                        $command,
                        $resolvedExecutionType->value
                    );

                    if (!isset($createResult['scriptids'][0])) {
                        $this->logZabbixResponse(
                            'script_creation_failed',
                            $createResult,
                            $correlationId
                        );

                        return $this->buildErrorResponse(
                            ZabbixErrorCode::SCRIPT_CREATION_FAILED,
                            'Unable to create the temporary Zabbix script.',
                            $correlationId
                        );
                    }

                    $scriptId = $createResult['scriptids'][0];

                    foreach ($hostIds as $hostId) {
                        $hostId = (string) $hostId;

                        try {
                            $execResult = $client->executeScript(
                                $scriptId,
                                $hostId
                            );

                            $output = $this->extractExecutionOutput(
                                $execResult
                            );

                            $results[$hostId]['value'] .= sprintf(
                                "Command: %s\nOutput:\n%s\n\n",
                                $command,
                                $output
                            );
                        } catch (\Throwable $e) {
                            $this->logException(
                                $e,
                                $correlationId,
                                [
                                    'operation' => 'script_execution',
                                    'host_id' => $hostId,
                                ]
                            );

                            $results[$hostId] = [
                                'status' => 'error',
                                'error_code' => ZabbixErrorCode::SCRIPT_EXECUTION_FAILED->value,
                                'message' => 'Zabbix script execution failed.',
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logException(
                        $e,
                        $correlationId,
                        [
                            'operation' => 'script_creation_or_execution',
                        ]
                    );

                    return $this->buildErrorResponse(
                        ZabbixErrorCode::UNKNOWN_ERROR,
                        'An unexpected error occurred while processing the Zabbix request.',
                        $correlationId
                    );
                } finally {
                    if ($scriptId !== null) {
                        $this->cleanupScript(
                            $client,
                            $scriptId,
                            $correlationId
                        );
                    }
                }
            }

            $hasExecutionErrors = false;

            foreach ($results as $result) {
                if (($result['status'] ?? null) === 'error') {
                    $hasExecutionErrors = true;
                    break;
                }
            }

            return [
                'status' => $hasExecutionErrors
                    ? 'partial_failure'
                    : 'success',
                'correlation_id' => $correlationId,
                'results' => $results,
            ];
        } catch (\Throwable $e) {
            $this->logException(
                $e,
                $correlationId,
                [
                    'operation' => 'tool_execution',
                ]
            );

            return $this->buildErrorResponse(
                ZabbixErrorCode::UNKNOWN_ERROR,
                'An unexpected error occurred while processing the Zabbix request.',
                $correlationId
            );
        }
    }

    /**
     * Convert a Zabbix execution response into a safe output string.
     */
    private function extractExecutionOutput(mixed $execResult): string
    {
        if (
            is_array($execResult) &&
            isset($execResult['value'])
        ) {
            return (string) $execResult['value'];
        }

        if (is_string($execResult)) {
            return $execResult;
        }

        $encoded = json_encode(
            $execResult,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $encoded !== false ? $encoded : '';
    }

    /**
     * Delete a temporary Zabbix script without turning a successful
     * execution into a failed execution.
     */
    private function cleanupScript(
        ZabbixClient $client,
        string $scriptId,
        string $correlationId
    ): void {
        try {
            $client->deleteScript($scriptId);
        } catch (\Throwable $e) {
            $this->logException(
                $e,
                $correlationId,
                [
                    'operation' => 'script_cleanup',
                    'script_id' => $scriptId,
                ]
            );
        }
    }

    /**
     * Build a safe response for the AI/tool consumer.
     *
     * No exception details, command contents or raw API responses
     * are exposed here.
     */
    private function buildErrorResponse(
        ZabbixErrorCode $errorCode,
        string $message,
        string $correlationId
    ): array {
        return [
            'status' => 'error',
            'error_code' => $errorCode->value,
            'message' => $message,
            'correlation_id' => $correlationId,
        ];
    }

    private function generateCorrelationId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Log an exception internally.
     *
     * Stack traces are never returned to the AI or caller.
     */
    private function logException(
        \Throwable $exception,
        string $correlationId,
        array $context = []
    ): void {
        $context['exception'] = $exception::class;
        $context['message'] = $this->sanitizeLogMessage(
            $exception->getMessage()
        );

        $context['trace'] = $exception->getTraceAsString();

        $encodedContext = json_encode(
            $context,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        error_log(sprintf(
            '[ZabbixAdHocRunner][%s] %s',
            $correlationId,
            $encodedContext !== false
                ? $encodedContext
                : 'Unable to encode exception context.'
        ));
    }

    /**
     * Log a security validation violation internally.
     *
     * The rejected command is intentionally NOT logged.
     */
    private function logSecurityViolation(
        CommandValidationException $exception,
        string $correlationId
    ): void {
        error_log(sprintf(
            '[ZabbixAdHocRunner][%s][SECURITY] Command rejected by security policy. OS=%s Reason=%s',
            $correlationId,
            $exception->getOperatingSystem()?->value ?? 'UNKNOWN',
            $this->sanitizeLogMessage($exception->getReason())
        ));
    }

    private function logEvent(
        string $message,
        string $correlationId
    ): void {
        error_log(sprintf(
            '[ZabbixAdHocRunner][%s] %s',
            $correlationId,
            $this->sanitizeLogMessage($message)
        ));
    }

    /**
     * Log Zabbix API failures without returning them to the AI.
     */
    private function logZabbixResponse(
        string $operation,
        mixed $response,
        string $correlationId
    ): void {
        $encodedResponse = json_encode(
            $response,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        error_log(sprintf(
            '[ZabbixAdHocRunner][%s][%s] Zabbix API response: %s',
            $correlationId,
            $operation,
            $encodedResponse !== false
                ? $this->sanitizeLogMessage($encodedResponse)
                : '[unable to encode response]'
        ));
    }

    /**
     * Redact common credential/token patterns from log messages.
     */
    private function sanitizeLogMessage(string $message): string
    {
        $patterns = [
            '/((?:password|passwd|pwd)\s*[=:]\s*)[^\s,;}"\']+/i',
            '/((?:token|api[_-]?key|secret)\s*[=:]\s*)[^\s,;}"\']+/i',
            '/(authorization\s*:\s*bearer\s+)[^\s,;}"\']+/i',
        ];

        foreach ($patterns as $pattern) {
            $message = preg_replace(
                $pattern,
                '$1[REDACTED]',
                $message
            ) ?? $message;
        }

        return $message;
    }

    protected function getClient(): ZabbixClient
    {
        if ($this->client === null) {
            try {
                $this->client = new ZabbixClient(
                    ZABBIX_ENDPOINT,
                    ZABBIX_USER,
                    ZABBIX_PASSWORD
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Failed to initialize Zabbix client.',
                    0,
                    $e
                );
            }
        }

        return $this->client;
    }
}