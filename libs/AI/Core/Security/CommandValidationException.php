<?php

namespace AI\Core\Security;

class CommandValidationException extends \RuntimeException
{
    public function __construct(
        private string $command,
        private string $reason,
        private ?OperatingSystem $operatingSystem = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $osStr = $operatingSystem ? " for OS [{$operatingSystem->value}]" : "";
        parent::__construct(
            "Command validation failed{$osStr}: '{$command}'. Reason: {$reason}",
            $code,
            $previous
        );
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getOperatingSystem(): ?OperatingSystem
    {
        return $this->operatingSystem;
    }
}
