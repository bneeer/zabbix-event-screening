<?php

namespace AI\Core\DTO;

use AI\Core\Exceptions\AiContractValidationException;

final readonly class ExecutionResult implements \JsonSerializable
{
    /**
     * @param string $status
     * @param string $message
     * @param array<string, HostExecutionResult> $results Keyed by hostId
     * @param array<int|string, mixed> $extra
     */
    public function __construct(
        public string $status,
        public string $message = '',
        public array $results = [],
        public array $extra = []
    ) {
        if (trim($status) === '') {
            throw new AiContractValidationException('ExecutionResult status cannot be empty.');
        }

        foreach ($results as $hostId => $hostResult) {
            if (!$hostResult instanceof HostExecutionResult) {
                throw new AiContractValidationException(sprintf(
                    'ExecutionResult results must contain instances of %s, %s found for hostId "%s".',
                    HostExecutionResult::class,
                    get_debug_type($hostResult),
                    $hostId
                ));
            }
        }
    }

    public function isSuccess(): bool
    {
        return strtolower($this->status) === 'success';
    }

    public function getHostResult(string $hostId): ?HostExecutionResult
    {
        return $this->results[$hostId] ?? null;
    }

    public function jsonSerialize(): array
    {
        $serializedResults = [];
        foreach ($this->results as $hostId => $result) {
            $serializedResults[$hostId] = [
                'status' => $result->status,
                'value' => $result->value,
                'raw' => $result->raw,
            ];
        }

        $data = [
            'status' => $this->status,
            'message' => $this->message,
            'results' => $serializedResults,
        ];

        if (!empty($this->extra)) {
            $data = array_merge($data, $this->extra);
        }

        return $data;
    }
}
