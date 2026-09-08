<?php

declare(strict_types=1);

namespace AI\Core\DTO;

use AI\Core\Exceptions\AiContractValidationException;

final readonly class ScannerResult implements \JsonSerializable, \Stringable
{
    /**
     * @param ScreeningStatus $status
     * @param string $rootCause
     * @param string $suggestedNextSteps
     * @param string $technicalEvidence
     * @param array<string> $affectedHosts
     * @param array<string, array<CommandExecutionResult>>|array<int, CommandExecutionResult> $commandExecutionResults
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public ScreeningStatus $status,
        public string $rootCause,
        public string $suggestedNextSteps,
        public string $technicalEvidence = '',
        public array $affectedHosts = [],
        public array $commandExecutionResults = [],
        public array $metadata = []
    ) {
        if (trim($rootCause) === '') {
            throw new AiContractValidationException('ScannerResult rootCause cannot be empty.');
        }

        if (trim($suggestedNextSteps) === '') {
            throw new AiContractValidationException('ScannerResult suggestedNextSteps cannot be empty.');
        }
    }

    public function isSuccess(): bool
    {
        return $this->status->isSuccess();
    }

    public function __toString(): string
    {
        return $this->rootCause;
    }

    public function getRootCauseAnalysis(): string
    {
        return $this->rootCause;
    }

    public function getSuggestedNextSteps(): string
    {
        return $this->suggestedNextSteps;
    }

    public function getTechnicalEvidence(): string
    {
        return $this->technicalEvidence;
    }

    public function getRawTechnicalOutput(): string
    {
        return $this->technicalEvidence;
    }

    public function getAffectedHosts(): array
    {
        return $this->affectedHosts;
    }

    public function getCommandExecutionResults(): array
    {
        return $this->commandExecutionResults;
    }

    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status->value,
            'root_cause' => $this->rootCause,
            'suggested_next_steps' => $this->suggestedNextSteps,
            'technical_evidence' => $this->technicalEvidence,
            'affected_hosts' => $this->affectedHosts,
            'command_execution_results' => $this->commandExecutionResults,
            'metadata' => $this->metadata,
        ];
    }
}
