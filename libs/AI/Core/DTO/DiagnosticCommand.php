<?php

namespace AI\Core\DTO;

use AI\Core\Exceptions\AiContractValidationException;

final readonly class DiagnosticCommand implements \Stringable, \JsonSerializable
{
    public string $command;

    public function __construct(string $command)
    {
        $trimmed = trim($command);
        if ($trimmed === '') {
            throw new AiContractValidationException('Diagnostic command cannot be empty.');
        }

        $this->command = $trimmed;
    }

    public function __toString(): string
    {
        return $this->command;
    }

    public function jsonSerialize(): string
    {
        return $this->command;
    }
}
