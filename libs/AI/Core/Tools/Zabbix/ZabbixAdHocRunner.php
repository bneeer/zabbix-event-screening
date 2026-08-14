<?php

namespace AI\Core\Tools\Zabbix;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Zabbix\Client as ZabbixClient;
use ZabbixClient7;

class ZabbixAdHocRunner extends Tool
{
    protected $client = null;

    public function __construct()
    {
        // Define Tool name and description
        parent::__construct(
            'run_zabbix_command',
            'Running a command via Zabbix on target hosts',
        );
    }

    /**
     * Return the list of properties.
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
                    name: 'execution_type',
                    type: PropertyType::STRING,
                    description: 'Type of execution: 0 - Script (Agent), 2 - SSH, 3 - Telnet',
                    required: false
                )
            ];
        } catch (ArrayPropertyException $e) {
            echo "Error defining property: " . $e->getMessage();
            return [];
        }
    }

    /**
     * Implementing the tool logic
     */
    public function __invoke(array $hostIds, array $commands, string $execution_type = "0"): array
    {
        try {
            $client = $this->getClient();
            $command_str = implode(' ; ', $commands);
            $script_name = "MCIAdHoc_" . md5($command_str);

            // Check if script already exists
            $existing_scripts = $client->getScriptByName($script_name);

            if (!empty($existing_scripts) && is_array($existing_scripts)) {
                $scriptid = $existing_scripts[0]['scriptid'];
            } else {
                // Create new script
                $result = $client->createScript($script_name, $command_str, $execution_type);
                if (isset($result['scriptids'])) {
                    $scriptid = $result['scriptids'][0];
                } else {
                    return [
                        'status' => 'error',
                        'message' => 'Failed to create Zabbix script',
                        'details' => $result
                    ];
                }
            }

            $results = [];
            foreach ($hostIds as $hostid) {
                $exec_result = $client->executeScript($scriptid, $hostid);
                $results[$hostid] = $exec_result;
            }

            // Delete the script after execution to avoid clutter
//            $client->deleteScript($scriptid);

            return [
                'status' => 'success',
                'script_id' => $scriptid,
                'results' => $results
            ];

        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => "Error running command via Zabbix: " . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }

    protected function getClient(): ZabbixClient
    {
        if ($this->client === null) {
            // Using TSBR constants as they seem to be the standard in the environment
            $endpoint = ZABBIX_ENDPOINT;
            $user = ZABBIX_USER;
            $password = ZABBIX_PASSWORD;

            $this->client = new ZabbixClient($endpoint, $user, $password);
        }
        return $this->client;
    }
}
