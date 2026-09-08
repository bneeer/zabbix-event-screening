<?php

declare(strict_types=1);

namespace AI\Core\Formatters;

use AI\Core\DTO\ScannerResult;

interface FormatterInterface
{
    /**
     * Formats a ScannerResult DTO into a specific string presentation.
     *
     * @param ScannerResult $result
     * @return string
     */
    public function format(ScannerResult $result): string;
}
