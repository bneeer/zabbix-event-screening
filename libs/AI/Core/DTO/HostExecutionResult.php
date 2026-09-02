<?php

namespace AI\Core\DTO;

use AI\Core\Exceptions\AiContractValidationException;

final readonly class HostExecutionResult implements \JsonSerializable
{
    /**
     * @param string $hostId
     * @param string $status
     * @param string $value
     * @param array<int|string, mixed> $raw
     */
    public function __construct(
        public string $hostId,
        public string $status,
        public string $value,
        public array $raw = []
    ) {
        if (trim($hostId) === '') {
            throw new AiContractValidationException('HostExecutionResult hostId cannot be empty.');
        }

        if (trim($status) === '') {
            throw new AiContractValidationException('HostExecutionResult status cannot be empty.');
        }
    }

    public function isSuccess(): bool
    {
        return strtolower($this->status) === 'success';
    }

    public function jsonSerialize(): array
    {
        return [
            'hostId' => $this->hostId,
            'status' => $this->status,
            'value' => $this->value,
            'raw' => $this->raw,
        ];
    }
}
