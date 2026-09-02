<?php

namespace AI\Core\DTO\Parser;

use AI\Core\DTO\ScreeningResult;
use AI\Core\DTO\ScreeningStatus;
use AI\Core\Exceptions\AiContractValidationException;

final class ScreeningResultParser
{
    /**
     * Parses the LLM agent text output into a typed ScreeningResult DTO.
     *
     * Expected sections:
     * 0- STATUS RESULT:
     * ######SUCCESS######## (or other valid ScreeningStatus)
     *
     * 1- ROOT-CAUSE ANALYSIS:
     * ...
     *
     * 2- SUGGESTED NEXT STEPS:
     * ...
     *
     * 3- TECHNICAL OUTPUT (RAW):
     * ...
     *
     * @param string $rawOutput
     * @return ScreeningResult
     * @throws AiContractValidationException
     */
    public static function parse(string $rawOutput): ScreeningResult
    {
        $trimmed = trim($rawOutput);
        if ($trimmed === '') {
            throw new AiContractValidationException('Screening agent output cannot be empty.');
        }

        // 0- STATUS RESULT:
        if (!preg_match('/0-\s*STATUS RESULT:\s*([^\n\r]+)/i', $trimmed, $statusMatches)) {
            throw new AiContractValidationException('Missing "0- STATUS RESULT:" section in screening output.');
        }

        $rawStatus = trim($statusMatches[1]);
        $status = ScreeningStatus::tryFromRaw($rawStatus);
        if ($status === null) {
            throw new AiContractValidationException(sprintf(
                'Invalid status "%s" in "0- STATUS RESULT:" section.',
                $rawStatus
            ));
        }

        // 1- ROOT-CAUSE ANALYSIS:
        if (!preg_match('/1-\s*ROOT-CAUSE ANALYSIS:\s*([\s\S]*?)(?=2-\s*SUGGESTED NEXT STEPS:|$)/i', $trimmed, $rcaMatches)) {
            throw new AiContractValidationException('Missing "1- ROOT-CAUSE ANALYSIS:" section in screening output.');
        }
        $rootCause = trim($rcaMatches[1]);
        if ($rootCause === '') {
            throw new AiContractValidationException('"1- ROOT-CAUSE ANALYSIS:" section cannot be empty.');
        }

        // 2- SUGGESTED NEXT STEPS:
        if (!preg_match('/2-\s*SUGGESTED NEXT STEPS:\s*([\s\S]*?)(?=3-\s*TECHNICAL OUTPUT \(RAW\):|$)/i', $trimmed, $stepsMatches)) {
            throw new AiContractValidationException('Missing "2- SUGGESTED NEXT STEPS:" section in screening output.');
        }
        $steps = trim($stepsMatches[1]);
        if ($steps === '') {
            throw new AiContractValidationException('"2- SUGGESTED NEXT STEPS:" section cannot be empty.');
        }

        // 3- TECHNICAL OUTPUT (RAW):
        $rawTech = '';
        if (preg_match('/3-\s*TECHNICAL OUTPUT \(RAW\):\s*([\s\S]*)$/i', $trimmed, $techMatches)) {
            $rawTech = trim($techMatches[1]);
        }

        return new ScreeningResult(
            status: $status,
            rootCauseAnalysis: $rootCause,
            suggestedNextSteps: $steps,
            rawTechnicalOutput: $rawTech,
            fullText: $trimmed
        );
    }
}
