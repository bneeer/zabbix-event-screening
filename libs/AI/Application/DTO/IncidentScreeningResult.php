<?php

declare(strict_types=1);

namespace AI\Application\DTO;

use AI\Core\DTO\DiagnosticPlan;
use AI\Core\DTO\ScannerResult;
use AI\Core\DTO\ScreeningResult;
use AI\Core\DTO\ZabbixHostMatchResult;
use AI\Core\Formatters\ConsoleFormatter;

final readonly class IncidentScreeningResult implements \JsonSerializable
{
    /**
     * @param string $incidentNumber
     * @param string $incidentSummary
     * @param ZabbixHostMatchResult $hostMatchResult
     * @param array<string, string> $hostIdsToOsMap
     * @param DiagnosticPlan $diagnosticPlan
     * @param ScannerResult|ScreeningResult $screeningResult
     * @param string $rawScannerOutput
     * @param string $itsmOutput
     * @param string|null $outputFilePath
     */
    public function __construct(
        public string $incidentNumber,
        public string $incidentSummary,
        public ZabbixHostMatchResult $hostMatchResult,
        public array $hostIdsToOsMap,
        public DiagnosticPlan $diagnosticPlan,
        public ScannerResult|ScreeningResult $screeningResult,
        public string $rawScannerOutput,
        public string $itsmOutput,
        public ?string $outputFilePath = null
    ) {
    }

    public function getScannerResult(): ScannerResult
    {
        if ($this->screeningResult instanceof ScannerResult) {
            return $this->screeningResult;
        }
        return $this->screeningResult->toScannerResult();
    }

    public function getFormattedReport(): string
    {
        return (new ConsoleFormatter())->format($this->getScannerResult());
    }

    public function getItsmOutput(): string
    {
        return $this->itsmOutput;
    }

    public function jsonSerialize(): array
    {
        return [
            'incident_number' => $this->incidentNumber,
            'incident_summary' => $this->incidentSummary,
            'host_ids' => $this->hostIdsToOsMap,
            'diagnostic_plan' => $this->diagnosticPlan,
            'screening_result' => $this->screeningResult,
            'raw_scanner_output' => $this->rawScannerOutput,
            'itsm_output' => $this->itsmOutput,
            'output_file_path' => $this->outputFilePath,
        ];
    }
}
