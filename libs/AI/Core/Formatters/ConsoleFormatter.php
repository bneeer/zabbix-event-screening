<?php

declare(strict_types=1);

namespace AI\Core\Formatters;

use AI\Core\DTO\ScannerResult;

final class ConsoleFormatter implements FormatterInterface
{
    public function format(ScannerResult $result): string
    {
        return "0- STATUS RESULT:\n"
            . $result->status->value . "\n\n"
            . "1- ROOT-CAUSE ANALYSIS:\n"
            . $result->rootCause . "\n\n"
            . "2- SUGGESTED NEXT STEPS:\n"
            . $result->suggestedNextSteps . "\n\n"
            . "3- TECHNICAL OUTPUT (RAW):\n"
            . $result->technicalEvidence;
    }
}
