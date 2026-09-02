<?php

namespace AI\Core\DTO;

use AI\Core\Exceptions\AiContractValidationException;

final readonly class ScreeningResult implements \JsonSerializable, \Stringable
{
    public function __construct(
        public ScreeningStatus $status,
        public string $rootCauseAnalysis,
        public string $suggestedNextSteps,
        public string $rawTechnicalOutput,
        public string $fullText = ''
    ) {
        if (trim($rootCauseAnalysis) === '') {
            throw new AiContractValidationException('ScreeningResult rootCauseAnalysis cannot be empty.');
        }

        if (trim($suggestedNextSteps) === '') {
            throw new AiContractValidationException('ScreeningResult suggestedNextSteps cannot be empty.');
        }
    }

    public function isSuccess(): bool
    {
        return $this->status->isSuccess();
    }

    public function __toString(): string
    {
        return $this->fullText !== '' ? $this->fullText : $this->toFormattedReport();
    }

    public function toFormattedReport(): string
    {
        return "0- STATUS RESULT:\n"
            . $this->status->value . "\n\n"
            . "1- ROOT-CAUSE ANALYSIS:\n"
            . $this->rootCauseAnalysis . "\n\n"
            . "2- SUGGESTED NEXT STEPS:\n"
            . $this->suggestedNextSteps . "\n\n"
            . "3- TECHNICAL OUTPUT (RAW):\n"
            . $this->rawTechnicalOutput;
    }

    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status->value,
            'rootCauseAnalysis' => $this->rootCauseAnalysis,
            'suggestedNextSteps' => $this->suggestedNextSteps,
            'rawTechnicalOutput' => $this->rawTechnicalOutput,
        ];
    }
}
