<?php

declare(strict_types=1);

namespace AI\Application;

use AI\Agents\Itsm\ItsmIncidentManager;
use AI\Agents\Zabbix\ZabbixAutoScreening;
use AI\Agents\Zabbix\ZabbixHostFinderAgent;
use AI\Agents\Zabbix\ZabbixScanner;
use AI\Application\DTO\IncidentScreeningResult;
use AI\Application\Exceptions\IncidentNotFoundException;
use AI\Application\Exceptions\IncidentScreeningException;
use AI\Application\Exceptions\NoHostsFoundException;
use AI\Core\DTO\DiagnosticPlan;
use AI\Core\DTO\Parser\DiagnosticPlanParser;
use AI\Core\DTO\Parser\HostFinderResultParser;
use AI\Core\DTO\Parser\ScreeningResultParser;
use AI\Core\DTO\Parser\ScannerResultParser;
use AI\Core\DTO\ScreeningResult;
use AI\Core\DTO\ZabbixHostMatchResult;
use AI\Core\Security\CommandValidationException;
use AI\Core\Security\CommandValidator;
use AI\Core\Security\OperatingSystem;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use ServiceNOW\Client as ServiceNOW;

class IncidentScreeningService
{
    public function __construct(
        private ?ServiceNOW $serviceNowClient = null,
        private ?ItsmIncidentManager $incidentManager = null,
        private ?ZabbixHostFinderAgent $hostFinderAgent = null,
        private ?ZabbixAutoScreening $autoScreeningAgent = null,
        private ?ZabbixScanner $scannerAgent = null,
        private ?CommandValidator $commandValidator = null
    ) {
        $this->commandValidator ??= new CommandValidator();
    }

    /**
     * Executes the end-to-end incident screening workflow.
     *
     * @param string $incidentNumber
     * @param string|null $outputDirectory
     * @param callable|null $onProgress
     * @return IncidentScreeningResult
     * @throws IncidentScreeningException
     */
    public function screenIncident(
        string $incidentNumber,
        ?string $outputDirectory = null,
        ?callable $onProgress = null
    ): IncidentScreeningResult {
        // 1. Incident Retrieval
        $record = $this->retrieveIncident($incidentNumber);
        $number = $record['number'] ?? $incidentNumber;

        $outputFilePath = null;
        if ($outputDirectory !== null) {
            if (!is_dir($outputDirectory)) {
                @mkdir($outputDirectory, 0777, true);
            }
            $outputFilePath = rtrim($outputDirectory, '/\\') . DIRECTORY_SEPARATOR . "{$number}_screening_" . date('YmdHis') . ".txt";
        }

        // 2. Incident Interpretation
        $incidentData = $this->interpretIncident($number, $onProgress);

        // 3. Host Identification
        $hostMatchResult = $this->identifyHosts($incidentData, $onProgress);
        $hostIds = $hostMatchResult->getHostIdToOsMap();

        if (empty($hostIds)) {
            throw new NoHostsFoundException("No Zabbix hosts found for incident {$number}. Skipping scan.");
        }

        // 4. Diagnostic Planning
        $diagnosticPlan = $this->planDiagnostics($incidentData, $hostIds, $onProgress);

        // 5. Command Validation
        $this->validateCommands($diagnosticPlan, $hostIds);

        // 6. Zabbix Execution & Evidence Collection
        $zabbixData = $this->executeDiagnostics($diagnosticPlan, $outputFilePath, $onProgress);

        // 7. Final Analysis & Report Generation
        [$screeningResult, $itsmOutput] = $this->generateReport($zabbixData);

        return new IncidentScreeningResult(
            incidentNumber: $number,
            incidentSummary: $incidentData,
            hostMatchResult: $hostMatchResult,
            hostIdsToOsMap: $hostIds,
            diagnosticPlan: $diagnosticPlan,
            screeningResult: $screeningResult,
            rawScannerOutput: $zabbixData,
            itsmOutput: $itsmOutput,
            outputFilePath: $outputFilePath
        );
    }

    /**
     * Retrieves the ServiceNow incident record.
     *
     * @param string $incidentNumber
     * @return array<string, mixed>
     * @throws IncidentNotFoundException
     */
    public function retrieveIncident(string $incidentNumber): array
    {
        if ($this->serviceNowClient === null) {
            throw new IncidentScreeningException('ServiceNOW client is not configured.');
        }

        $incident = $this->serviceNowClient->RetrieveRecord(table: 'incident', number: $incidentNumber);

        if (!is_array($incident) || !($incident['status'] ?? false) || empty($incident['data'])) {
            $message = is_array($incident) ? ($incident['Message'] ?? 'Unknown error') : 'No response';
            throw new IncidentNotFoundException("Error fetching record: {$message}");
        }

        return $incident['data'][0];
    }

    /**
     * Summarizes the incident using the ITSM incident manager agent.
     *
     * @param string $incidentNumber
     * @param callable|null $onProgress
     * @return string
     */
    public function interpretIncident(string $incidentNumber, ?callable $onProgress = null): string
    {
        $this->notify($onProgress, "--- Fetching Incident: {$incidentNumber} ---\n");

        $agent = $this->incidentManager ?? ItsmIncidentManager::make();
        $stream = $agent->stream(new UserMessage("Show me a summary of incident {$incidentNumber}"));

        $incidentData = '';
        foreach ($stream->events() as $chunk) {
            if ($chunk instanceof TextChunk) {
                $incidentData .= $chunk->content;
            }
        }

        $this->notify($onProgress, $incidentData . "\n\n");

        return $incidentData;
    }

    /**
     * Identifies Zabbix host IDs from the interpreted incident data.
     *
     * @param string $incidentData
     * @param callable|null $onProgress
     * @return ZabbixHostMatchResult
     */
    public function identifyHosts(string $incidentData, ?callable $onProgress = null): ZabbixHostMatchResult
    {
        $this->notify($onProgress, "--- Finding Zabbix Hosts ---\n");

        $agent = $this->hostFinderAgent ?? ZabbixHostFinderAgent::make();
        $response = $agent->chat(new UserMessage("Find Zabbix hostIds in this incident: {$incidentData}"))
            ->getMessage()
            ->getContent();

        $matchResult = HostFinderResultParser::parse($response);
        $hostIds = $matchResult->getHostIdToOsMap();

        if (!empty($hostIds)) {
            $this->notify($onProgress, "Found Host IDs: " . implode(", ", array_keys($hostIds)) . "\n\n");
        }

        return $matchResult;
    }

    /**
     * Plans diagnostic commands for the identified hosts.
     *
     * @param string $incidentData
     * @param array<string, string> $hostIds
     * @param callable|null $onProgress
     * @return DiagnosticPlan
     */
    public function planDiagnostics(string $incidentData, array $hostIds, ?callable $onProgress = null): DiagnosticPlan
    {
        $this->notify($onProgress, "--- Screening for Commands ---\n");

        $enrichedIncidentData = $incidentData . "\nOperating System per hostId:\n";
        foreach ($hostIds as $id => $os) {
            $enrichedIncidentData .= $id . ': ' . $os . "\n";
        }

        $agent = $this->autoScreeningAgent ?? ZabbixAutoScreening::make();
        $rawPlan = $agent->chat(new UserMessage("Make a triage for this incident: {$enrichedIncidentData}"))
            ->getMessage()
            ->getContent();

        $plan = DiagnosticPlanParser::parse($rawPlan)->withHostIds($hostIds);
        $planJson = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->notify($onProgress, $planJson . "\n\n");

        return $plan;
    }

    /**
     * Validates that all planned diagnostic commands comply with security policies.
     *
     * @param DiagnosticPlan $diagnosticPlan
     * @param array<string, string> $hostIds
     * @throws CommandValidationException
     */
    public function validateCommands(DiagnosticPlan $diagnosticPlan, array $hostIds): void
    {
        $operatingSystems = array_unique(array_values($hostIds));

        foreach ($diagnosticPlan->commands as $command) {
            $cmdString = (string)$command;

            if (empty($operatingSystems)) {
                $this->commandValidator->validateCrossPlatform($cmdString);
                continue;
            }

            foreach ($operatingSystems as $osName) {
                $os = OperatingSystem::fromString($osName);
                if ($os === OperatingSystem::UNKNOWN) {
                    $this->commandValidator->validateCrossPlatform($cmdString);
                } else {
                    $this->commandValidator->validate($cmdString, $os);
                }
            }
        }
    }

    /**
     * Executes the diagnostic plan via Zabbix agent scanner.
     *
     * @param DiagnosticPlan $diagnosticPlan
     * @param string|null $outputFilePath
     * @param callable|null $onProgress
     * @return string
     */
    public function executeDiagnostics(
        DiagnosticPlan $diagnosticPlan,
        ?string $outputFilePath = null,
        ?callable $onProgress = null
    ): string {
        $this->notify($onProgress, "--- Running Zabbix Scan ---\n");

        $planJson = json_encode($diagnosticPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $agent = $this->scannerAgent ?? ZabbixScanner::make();
        $stream = $agent->stream(new UserMessage("According to the following JSON, run the commands in the servers: {$planJson}"));

        $zabbixData = '';
        foreach ($stream->events() as $chunk) {
            if ($chunk instanceof TextChunk) {
                $this->notify($onProgress, $chunk->content);
                $zabbixData .= $chunk->content;

                if ($outputFilePath !== null) {
                    file_put_contents($outputFilePath, $chunk->content, FILE_APPEND);
                }
            }
        }

        return $zabbixData;
    }

    /**
     * Generates the structured screening report and ITSM response.
     *
     * @param string $zabbixData
     * @return array{0: ScannerResult, 1: string}
     */
    public function generateReport(string $zabbixData): array
    {
        $scannerResult = ScannerResultParser::parse($zabbixData);
        $serviceNowFormatter = new \AI\Core\Formatters\ServiceNowFormatter();
        $itsmOutput = $serviceNowFormatter->format($scannerResult);

        return [$scannerResult, $itsmOutput];
    }

    private function notify(?callable $onProgress, string $message): void
    {
        if ($onProgress !== null) {
            $onProgress($message);
        }
    }
}
