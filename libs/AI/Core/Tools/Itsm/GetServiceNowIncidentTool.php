<?php

namespace AI\Core\Tools\Itsm;

use GuzzleHttp\Client;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetServiceNowIncidentTool extends Tool
{
    protected Client $client;

    public function __construct()
    {
        parent::__construct(
            'get_servicenow_record',
            'Retrieve a ServiceNow incident by number or sys_id'
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'number',
                type: PropertyType::STRING,
                description: 'Incident number Required',
                required: false
            )
        ];
    }

    public function __invoke(?string $number = null): string
    {
        try {

            // NUMBER
            if (!empty($number)) {

                $response = $this->getClient()->get("api/now/table/incident?number=$number&sysparm_limt=1&sysparm_display_value=true&sysparm_exclude_reference_link=true");

                $data = json_decode($response->getBody()->getContents(), true);

                $data['result'][0]['hostname'] = explode(' ', $data['result'][0]['cmdb_ci'])[0];

                return json_encode($data['result'][0]);
            }

            return json_encode([]);

        } catch (\Throwable $e) {
            return json_encode([
                'success' => false,
                'error' => 'Failed to fetch ServiceNow record',
                'message' => $e->getMessage()
            ]);
        }
    }

    protected function getClient(): Client
    {
        return $this->client ??= new Client([
            'base_uri' => SERVICENOW_INSTANCE_CUSTOM_URL,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(SERVICENOW_INSTANCE_USER . ':' . SERVICENOW_INSTANCE_PASSWORD),
                'Accept'        => 'application/json',
            ],
            'proxy' => [
                'http'  => PROXY,
                'https' => PROXY,
            ],
            'verify' => false,
            'timeout' => 30
        ]);
    }
}