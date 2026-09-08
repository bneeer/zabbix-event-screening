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
            $correlationId = bin2hex(random_bytes(16));
            error_log(sprintf(
                '[GetServiceNowIncidentTool][%s] Failed to fetch ServiceNow record: %s%s',
                $correlationId,
                $e->getMessage(),
                PHP_EOL . $e->getTraceAsString()
            ));

            return json_encode([
                'success' => false,
                'error_code' => 'SNOW_RECORD_FETCH_FAILED',
                'message' => 'Unable to retrieve ServiceNow incident record.',
                'correlation_id' => $correlationId,
            ]);
        }
    }

    protected function getClient(): Client
    {
        $caBundle = (defined('SERVICENOW_CA_BUNDLE') && SERVICENOW_CA_BUNDLE)
            ? SERVICENOW_CA_BUNDLE
            : ((defined('SSL_CA_BUNDLE') && SSL_CA_BUNDLE) ? SSL_CA_BUNDLE : null);

        if ($caBundle !== null && $caBundle !== '') {
            if (!file_exists($caBundle)) {
                throw new \RuntimeException("Configured ServiceNow CA bundle file not found: {$caBundle}");
            }
            $verify = $caBundle;
        } else {
            $verify = true;
        }

        return $this->client ??= new Client([
            'base_uri' => defined('SERVICENOW_INSTANCE_CUSTOM_URL') ? SERVICENOW_INSTANCE_CUSTOM_URL : null,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode((defined('SERVICENOW_INSTANCE_USER') ? SERVICENOW_INSTANCE_USER : '') . ':' . (defined('SERVICENOW_INSTANCE_PASSWORD') ? SERVICENOW_INSTANCE_PASSWORD : '')),
                'Accept'        => 'application/json',
            ],
            'proxy' => [
                'http'  => defined('PROXY') ? PROXY : null,
                'https' => defined('PROXY') ? PROXY : null,
            ],
            'verify' => $verify,
            'timeout' => 30
        ]);
    }
}