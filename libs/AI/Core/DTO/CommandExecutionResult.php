<?php

declare(strict_types=1);

namespace AI\Core\DTO;

use AI\Core\Exceptions\AiContractValidationException;

final readonly class CommandExecutionResult implements \JsonSerializable
{
    public function __construct(
        public string $command,
        public string $output,
        public string $status = 'success',
        public ?string $hostId = null,
        public ?int $exitCode = null,
        public array $raw = []
    ) {
        if (trim($command) === '') {
            throw new AiContractValidationException('CommandExecutionResult command cannot be empty.');
        }
    }

    public function isSuccess(): bool
    {
        return strtolower($this->status) === 'success';
    }

    public function jsonSerialize(): array
    {
        return [
            'command' => $this->command,
            'output' => $this->output,
            'status' => $this->status,
            'host_id' => $this->hostId,
            'exit_code' => $this->exitCode,
            'raw' => $this->raw,
        ];
    }
}
