<?php

require __DIR__ . "/../loader.php";

use AI\Application\IncidentScreeningService;
use ServiceNOW\Client as ServiceNOW;

try {
    global $db_dat;

    $lockFile = fopen(__DIR__ . '/run.lock', 'c');

    if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
        exit("Script is already running...\n\n");
    }

    // Dependency creation / resolution
    $snow = new ServiceNOW(
        instance: SERVICENOW_INSTANCE_CUSTOM_URL,
        authentication_type: "basic",
        authentication_data: [
            'username' => SERVICENOW_INSTANCE_USER,
            'password' => SERVICENOW_INSTANCE_PASSWORD
        ],
        proxy: PROXY,
        secure: true,
        custom_url: true,
        ca_bundle: defined('SERVICENOW_CA_BUNDLE') ? SERVICENOW_CA_BUNDLE : null
    );

    $service = new IncidentScreeningService(
        serviceNowClient: $snow
    );

    // Reading the requested incident
    $incidentNumber = $argv[1] ?? 'INC09827002';
    $outputDir = __DIR__ . '/tmp';

    // Invoking the application service with progress output
    $result = $service->screenIncident(
        incidentNumber: $incidentNumber,
        outputDirectory: $outputDir,
        onProgress: static function (string $message): void {
            echo $message;
        }
    );

    $screening_output = $result->getFormattedReport();
    $itsm_output = $result->getItsmOutput();

    echo $itsm_output;

} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}