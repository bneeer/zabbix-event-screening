<?php

declare(strict_types=1);

namespace AI\Core\Formatters;

use AI\Core\DTO\ScannerResult;

final class JsonFormatter implements FormatterInterface
{
    public function __construct(
        private readonly bool $pretty = true
    ) {
    }

    public function format(ScannerResult $result): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($this->pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($result, $flags) ?: '{}';
    }
}
