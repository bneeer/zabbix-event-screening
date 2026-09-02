<?php

namespace AI\Core\Tools\Zabbix;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Zabbix\Client as ZabbixClient;

class ZabbixHostFinder extends Tool
{
    protected $client = null;

    public function __construct()
    {
        // Define Tool name and description
        parent::__construct(
            'find_zabbix_hosts',
            'Search for Zabbix host IDs based on hostnames',
        );
    }

    /**
     * Return the list of properties.
     */
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'hostnames',
                type: PropertyType::ARRAY,
                description: 'The list of hostnames to search for in Zabbix',
                required: true
            )
        ];
    }

    /**
     * Implementing the tool logic
     */
    public function __invoke(array $hostnames): array
    {
        try {
            $client = $this->getClient();
            $token = $client->getAccessToken();
            
            // In Zabbix API, we can use host.get with filter or search
            $params = [
                "jsonrpc" => "2.0",
                "method" => "host.get",
                "params" => [
                    "output" => ["hostid", "host", "name"],
                    "selectInventory" => "extend",
                    "search" => [
                        "name" => $hostnames
                    ]
                ],
                "auth" => $token,
                "id" => 1
            ];

            // ZabbixClient class has a generic way to call methods or we can use curlRequest if we have access
            // Looking at ZabbixClient, it has a method searchEvents, but not a generic host getter exposed easily.
            // However, most methods in ZabbixClient follow a pattern.
            // Let's see if we can use a direct call if ZabbixClient allows or implement it.
            
            // Since ZabbixClient is already instantiated and authenticated in getClient():
            $result = $client->curlRequest($params);

            if ($result['status'] && isset($result['data']['result'])) {
                return $result['data']['result'];
            }

            return [];

        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => "Error finding hosts via Zabbix: " . $e->getMessage()
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
