<?php

namespace AI\Core\DTO\Parser;

use AI\Core\DTO\DiagnosticCommand;
use AI\Core\DTO\DiagnosticPlan;
use AI\Core\Exceptions\AiContractValidationException;

final class DiagnosticPlanParser
{
    /**
     * Allowed top-level fields for strict validation.
     */
    private const ALLOWED_FIELDS = ['customer', 'analysis', 'commands', 'hostIds'];

    /**
     * @param string|array<string, mixed> $input
     * @param bool $strict If true, rejects unexpected extra top-level fields
     * @return DiagnosticPlan
     * @throws AiContractValidationException
     */
    public static function parse(string|array $input, bool $strict = false): DiagnosticPlan
    {
        $data = is_array($input) ? $input : JsonHelper::extractJsonArray($input);

        if ($strict) {
            $extraKeys = array_diff(array_keys($data), self::ALLOWED_FIELDS);
            if (!empty($extraKeys)) {
                throw new AiContractValidationException(sprintf(
                    'Unexpected extra fields in DiagnosticPlan payload: %s',
                    implode(', ', $extraKeys)
                ));
            }
        }

        if (!array_key_exists('customer', $data)) {
            throw new AiContractValidationException('Missing required field "customer" in diagnostic plan.');
        }

        if (!is_string($data['customer'])) {
            throw new AiContractValidationException(sprintf(
                'Field "customer" must be a string, got %s.',
                gettype($data['customer'])
            ));
        }

        if (!array_key_exists('analysis', $data)) {
            throw new AiContractValidationException('Missing required field "analysis" in diagnostic plan.');
        }

        if (!is_string($data['analysis'])) {
            throw new AiContractValidationException(sprintf(
                'Field "analysis" must be a string, got %s.',
                gettype($data['analysis'])
            ));
        }

        if (!array_key_exists('commands', $data)) {
            throw new AiContractValidationException('Missing required field "commands" in diagnostic plan.');
        }

        if (!is_array($data['commands'])) {
            throw new AiContractValidationException(sprintf(
                'Field "commands" must be an array, got %s.',
                gettype($data['commands'])
            ));
        }

        if (empty($data['commands'])) {
            throw new AiContractValidationException('Field "commands" cannot be empty in diagnostic plan.');
        }

        $diagnosticCommands = [];
        foreach ($data['commands'] as $index => $cmd) {
            if (!is_string($cmd)) {
                throw new AiContractValidationException(sprintf(
                    'Each command in "commands" must be a string, got %s at index %s.',
                    gettype($cmd),
                    (string)$index
                ));
            }

            $diagnosticCommands[] = new DiagnosticCommand($cmd);
        }

        $hostIds = [];
        if (array_key_exists('hostIds', $data)) {
            if (!is_array($data['hostIds'])) {
                throw new AiContractValidationException(sprintf(
                    'Field "hostIds" must be an array, got %s.',
                    gettype($data['hostIds'])
                ));
            }

            foreach ($data['hostIds'] as $hostId => $os) {
                if (!is_string($hostId) && !is_int($hostId)) {
                    throw new AiContractValidationException('Keys in "hostIds" must be host identifiers.');
                }
                if (!is_string($os)) {
                    throw new AiContractValidationException(sprintf(
                        'Operating system value in "hostIds" must be a string, got %s for host %s.',
                        gettype($os),
                        (string)$hostId
                    ));
                }
                $hostIds[(string)$hostId] = $os;
            }
        }

        return new DiagnosticPlan(
            customer: $data['customer'],
            analysis: $data['analysis'],
            commands: $diagnosticCommands,
            hostIds: $hostIds
        );
    }
}
