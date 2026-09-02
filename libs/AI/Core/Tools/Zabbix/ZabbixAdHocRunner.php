<?php

namespace AI\Core\Tools\Zabbix;

use AI\Core\Security\CommandPolicy;
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
        // Define Tool name and description
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
    public function setExecutionType(ExecutionType|int|string|null $executionType): self
    {
        $this->executionType = $executionType;
        return $this;
    }

    /**
     * Return the list of properties exposed to the AI agent.
     * Note: execution_type is intentionally removed to prevent the AI from choosing infrastructure execution channels.
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
        } catch (ArrayPropertyException $e) {
            echo "Error defining property: " . $e->getMessage();
            return [];
        }
    }

    /**
     * Implementing the tool logic.
     *
     * Note: $execution_type is retained in the signature purely for legacy backward-compatibility,
     * but is explicitly IGNORED. The runner strictly uses the infrastructure-configured execution type.
     */
    public function __invoke(array $hostIds, array $commands, mixed $execution_type = null, ?string $operating_system = null): array
    {
        try {
            if (empty($commands)) {
                return [
                    'status' => 'error',
                    'message' => 'No commands provided for execution'
                ];
            }

            if (empty($hostIds)) {
                return [
                    'status' => 'error',
                    'message' => 'No host IDs provided for execution'
                ];
            }

            // 1. Resolve and validate infrastructure configured execution_type
            $resolvedExecutionType = $this->resolveExecutionType();
            if ($resolvedExecutionType === null || !$resolvedExecutionType->isSupported()) {
                $rawConfig = $this->executionType instanceof ExecutionType
                    ? $this->executionType->name . ' (' . $this->executionType->value . ')'
                    : (string) ($this->executionType ?? (defined('ZABBIX_EXECUTION_TYPE') ? ZABBIX_EXECUTION_TYPE : ($_ENV['ZABBIX_EXECUTION_TYPE'] ?? 'unknown')));

                return [
                    'status' => 'error',
                    'message' => "Invalid or unsupported configured execution_type '{$rawConfig}'. Allowed types: 0 (Agent), 2 (SSH), 3 (Telnet)"
                ];
            }

            // 2. Mandatory Pre-Execution Command Validation
            // A command that fails validation must NEVER be sent to Zabbix.
            $validatedCommands = [];
            $targetOs = OperatingSystem::fromString($operating_system);

            foreach ($commands as $cmd) {
                if ($targetOs !== OperatingSystem::UNKNOWN) {
                    $validatedCommands[] = $this->validator->validate($cmd, $targetOs);
                } else {
                    $validatedCommands[] = $this->validator->validateCrossPlatform($cmd);
                }
            }

            $client = $this->getClient();
            $results = [];

            foreach ($hostIds as $hostid) {
                $results[$hostid] = [
                    'response' => 'success',
                    'value' => ''
                ];
            }

            // 3. Execute each validated command individually (no chaining with ';')
            foreach ($validatedCommands as $parsedCmd) {
                $commandStr = $parsedCmd->getRaw();
                $scriptName = "MCIAdHoc_" . md5($commandStr . "_" . time() . "_" . uniqid());
                $scriptid = null;

                try {
                    $createResult = $client->createScript($scriptName, $commandStr, $resolvedExecutionType->value);

                    if (isset($createResult['scriptids'][0])) {
                        $scriptid = $createResult['scriptids'][0];
                    } else {
                        return [
                            'status' => 'error',
                            'message' => 'Failed to create Zabbix script',
                            'details' => $createResult
                        ];
                    }

                    foreach ($hostIds as $hostid) {
                        $execResult = $client->executeScript($scriptid, $hostid);
                        $output = is_array($execResult) && isset($execResult['value'])
                            ? $execResult['value']
                            : (is_string($execResult) ? $execResult : json_encode($execResult));

                        $results[$hostid]['value'] .= "Command: {$commandStr}\nOutput:\n{$output}\n\n";
                    }
                } finally {
                    // Guaranteed cleanup: delete temporary script from Zabbix
                    if ($scriptid !== null) {
                        $client->deleteScript($scriptid);
                    }
                }
            }

            return [
                'status' => 'success',
                'results' => $results
            ];

        } catch (CommandValidationException $e) {
            return [
                'status' => 'error',
                'message' => "Security boundary rejected command: " . $e->getMessage(),
                'command' => $e->getCommand(),
                'reason' => $e->getReason()
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => "Error running command via Zabbix: " . $e->getMessage()
            ];
        }
    }

    protected function getClient(): ZabbixClient
    {
        if ($this->client === null) {
            $endpoint = ZABBIX_ENDPOINT;
            $user = ZABBIX_USER;
            $password = ZABBIX_PASSWORD;

            $this->client = new ZabbixClient($endpoint, $user, $password);
        }
        return $this->client;
    }
}
