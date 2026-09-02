<?php

namespace AI\Core\DTO\Parser;

use AI\Core\DTO\ExecutionResult;
use AI\Core\DTO\HostExecutionResult;
use AI\Core\Exceptions\AiContractValidationException;

final class ExecutionResultParser
{
    /**
     * @param string|array<string, mixed> $input
     * @return ExecutionResult
     * @throws AiContractValidationException
     */
    public static function parse(string|array $input): ExecutionResult
    {
        $data = is_array($input) ? $input : JsonHelper::extractJsonArray($input);

        if (!array_key_exists('status', $data)) {
            throw new AiContractValidationException('Missing required field "status" in execution result.');
        }

        if (!is_string($data['status'])) {
            throw new AiContractValidationException(sprintf(
                'Field "status" must be a string, got %s.',
                gettype($data['status'])
            ));
        }

        $message = '';
        if (array_key_exists('message', $data)) {
            if (!is_string($data['message'])) {
                throw new AiContractValidationException(sprintf(
                    'Field "message" must be a string, got %s.',
                    gettype($data['message'])
                ));
            }
            $message = $data['message'];
        }

        $hostResults = [];
        if (array_key_exists('results', $data)) {
            if (!is_array($data['results'])) {
                throw new AiContractValidationException(sprintf(
                    'Field "results" must be an array/map of host results, got %s.',
                    gettype($data['results'])
                ));
            }

            foreach ($data['results'] as $hostId => $item) {
                if (!is_string($hostId) && !is_int($hostId)) {
                    throw new AiContractValidationException('Host ID key must be a valid string or integer.');
                }

                if (!is_array($item)) {
                    throw new AiContractValidationException(sprintf(
                        'Result item for host "%s" must be an array, got %s.',
                        (string)$hostId,
                        gettype($item)
                    ));
                }

                $hostStatus = $item['status'] ?? 'unknown';
                if (!is_string($hostStatus)) {
                    throw new AiContractValidationException(sprintf(
                        'Status for host "%s" must be a string, got %s.',
                        (string)$hostId,
                        gettype($hostStatus)
                    ));
                }

                $hostValue = $item['value'] ?? '';
                if (!is_string($hostValue)) {
                    throw new AiContractValidationException(sprintf(
                        'Value for host "%s" must be a string, got %s.',
                        (string)$hostId,
                        gettype($hostValue)
                    ));
                }

                $hostRaw = is_array($item['raw'] ?? null) ? $item['raw'] : [];

                $hostResults[(string)$hostId] = new HostExecutionResult(
                    hostId: (string)$hostId,
                    status: $hostStatus,
                    value: $hostValue,
                    raw: $hostRaw
                );
            }
        }

        $extra = [];
        foreach ($data as $key => $val) {
            if (!in_array($key, ['status', 'message', 'results'], true)) {
                $extra[$key] = $val;
            }
        }

        return new ExecutionResult(
            status: $data['status'],
            message: $message,
            results: $hostResults,
            extra: $extra
        );
    }
}
