<?php

declare(strict_types=1);

namespace AI\Core\DTO\Parser;

use AI\Core\DTO\CommandExecutionResult;
use AI\Core\DTO\ScannerResult;
use AI\Core\DTO\ScreeningStatus;
use AI\Core\Exceptions\AiContractValidationException;

final class ScannerResultParser
{
    /**
     * Parses and validates a structured scanner response into a ScannerResult DTO.
     *
     * @param string|array<string, mixed> $input
     * @param bool $strict
     * @return ScannerResult
     * @throws AiContractValidationException
     */
    public static function parse(string|array $input, bool $strict = false): ScannerResult
    {
        if (is_string($input)) {
            $trimmed = trim($input);
            if ($trimmed === '') {
                throw new AiContractValidationException('Scanner output cannot be empty.');
            }

            // Check if input is legacy plain text format (0- STATUS RESULT:)
            if (!str_starts_with($trimmed, '{') && str_contains($trimmed, '0- STATUS RESULT:')) {
                return self::parseLegacyText($trimmed);
            }

            $data = JsonHelper::extractJsonArray($trimmed);
        } else {
            $data = $input;
        }

        // 1. Validate 'status'
        if (!array_key_exists('status', $data)) {
            throw new AiContractValidationException('Missing required field "status" in scanner result.');
        }

        if (!is_string($data['status'])) {
            throw new AiContractValidationException(sprintf(
                'Field "status" must be a string, got %s.',
                gettype($data['status'])
            ));
        }

        $status = ScreeningStatus::tryFromRaw($data['status']);
        if ($status === null) {
            throw new AiContractValidationException(sprintf(
                'Invalid status "%s" in scanner result.',
                $data['status']
            ));
        }

        // 2. Validate 'root_cause'
        $rootCauseKey = null;
        foreach (['root_cause', 'rootCause', 'root_cause_analysis', 'rootCauseAnalysis'] as $candidate) {
            if (array_key_exists($candidate, $data)) {
                $rootCauseKey = $candidate;
                break;
            }
        }

        if ($rootCauseKey === null) {
            throw new AiContractValidationException('Missing required field "root_cause" in scanner result.');
        }

        if (!is_string($data[$rootCauseKey])) {
            throw new AiContractValidationException(sprintf(
                'Field "root_cause" must be a string, got %s.',
                gettype($data[$rootCauseKey])
            ));
        }

        $rootCause = trim($data[$rootCauseKey]);
        if ($rootCause === '') {
            throw new AiContractValidationException('Field "root_cause" cannot be empty in scanner result.');
        }

        // 3. Validate 'suggested_next_steps'
        $stepsKey = null;
        foreach (['suggested_next_steps', 'suggestedNextSteps', 'next_steps', 'nextSteps'] as $candidate) {
            if (array_key_exists($candidate, $data)) {
                $stepsKey = $candidate;
                break;
            }
        }

        if ($stepsKey === null) {
            throw new AiContractValidationException('Missing required field "suggested_next_steps" in scanner result.');
        }

        if (!is_string($data[$stepsKey]) && !is_array($data[$stepsKey])) {
            throw new AiContractValidationException(sprintf(
                'Field "suggested_next_steps" must be a string or array, got %s.',
                gettype($data[$stepsKey])
            ));
        }

        $suggestedNextSteps = is_array($data[$stepsKey])
            ? implode("\n", array_map('strval', $data[$stepsKey]))
            : trim((string)$data[$stepsKey]);

        if ($suggestedNextSteps === '') {
            throw new AiContractValidationException('Field "suggested_next_steps" cannot be empty in scanner result.');
        }

        // 4. Validate 'technical_evidence'
        $techKey = null;
        foreach (['technical_evidence', 'technicalEvidence', 'raw_technical_output', 'rawTechnicalOutput', 'technical_output', 'technicalOutput'] as $candidate) {
            if (array_key_exists($candidate, $data)) {
                $techKey = $candidate;
                break;
            }
        }

        $technicalEvidence = '';
        if ($techKey !== null) {
            if (is_array($data[$techKey])) {
                $technicalEvidence = json_encode($data[$techKey], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
            } elseif (is_string($data[$techKey])) {
                $technicalEvidence = trim($data[$techKey]);
            }
        }

        // 5. Validate 'affected_hosts'
        $hostsKey = null;
        foreach (['affected_hosts', 'affectedHosts', 'hosts'] as $candidate) {
            if (array_key_exists($candidate, $data)) {
                $hostsKey = $candidate;
                break;
            }
        }

        $affectedHosts = [];
        if ($hostsKey !== null) {
            if (!is_array($data[$hostsKey])) {
                throw new AiContractValidationException(sprintf(
                    'Field "affected_hosts" must be an array, got %s.',
                    gettype($data[$hostsKey])
                ));
            }
            foreach ($data[$hostsKey] as $idx => $host) {
                if (is_string($host) || is_int($host)) {
                    $affectedHosts[] = (string)$host;
                } elseif (is_array($host) && isset($host['hostid'])) {
                    $affectedHosts[] = (string)$host['hostid'];
                } elseif (is_array($host) && isset($host['host'])) {
                    $affectedHosts[] = (string)$host['host'];
                }
            }
        }

        // 6. Validate 'command_execution_results'
        $cmdResultsKey = null;
        foreach (['command_execution_results', 'commandExecutionResults', 'commands', 'results'] as $candidate) {
            if (array_key_exists($candidate, $data)) {
                $cmdResultsKey = $candidate;
                break;
            }
        }

        $commandExecutionResults = [];
        if ($cmdResultsKey !== null && is_array($data[$cmdResultsKey])) {
            foreach ($data[$cmdResultsKey] as $key => $item) {
                if ($item instanceof CommandExecutionResult) {
                    $commandExecutionResults[$key] = $item;
                } elseif (is_array($item)) {
                    if (isset($item['command'])) {
                        $commandExecutionResults[] = new CommandExecutionResult(
                            command: (string)$item['command'],
                            output: (string)($item['output'] ?? ''),
                            status: (string)($item['status'] ?? 'success'),
                            hostId: isset($item['host_id']) ? (string)$item['host_id'] : (is_string($key) ? $key : null),
                            exitCode: isset($item['exit_code']) ? (int)$item['exit_code'] : null,
                            raw: is_array($item['raw'] ?? null) ? $item['raw'] : []
                        );
                    } else {
                        // Group of commands by hostId
                        $hostList = [];
                        foreach ($item as $subItem) {
                            if (is_array($subItem) && isset($subItem['command'])) {
                                $hostList[] = new CommandExecutionResult(
                                    command: (string)$subItem['command'],
                                    output: (string)($subItem['output'] ?? ''),
                                    status: (string)($subItem['status'] ?? 'success'),
                                    hostId: (string)$key,
                                    exitCode: isset($subItem['exit_code']) ? (int)$subItem['exit_code'] : null,
                                    raw: is_array($subItem['raw'] ?? null) ? $subItem['raw'] : []
                                );
                            }
                        }
                        if (!empty($hostList)) {
                            $commandExecutionResults[(string)$key] = $hostList;
                        }
                    }
                }
            }
        }

        // Collect metadata
        $knownKeys = [
            'status', 'root_cause', 'rootCause', 'root_cause_analysis', 'rootCauseAnalysis',
            'suggested_next_steps', 'suggestedNextSteps', 'next_steps', 'nextSteps',
            'technical_evidence', 'technicalEvidence', 'raw_technical_output', 'rawTechnicalOutput', 'technical_output', 'technicalOutput',
            'affected_hosts', 'affectedHosts', 'hosts',
            'command_execution_results', 'commandExecutionResults', 'commands', 'results', 'metadata'
        ];
        $metadata = [];
        foreach ($data as $k => $v) {
            if (!in_array($k, $knownKeys, true)) {
                $metadata[$k] = $v;
            }
        }

        return new ScannerResult(
            status: $status,
            rootCause: $rootCause,
            suggestedNextSteps: $suggestedNextSteps,
            technicalEvidence: $technicalEvidence,
            affectedHosts: $affectedHosts,
            commandExecutionResults: $commandExecutionResults,
            metadata: $metadata
        );
    }

    private static function parseLegacyText(string $text): ScannerResult
    {
        $screening = ScreeningResultParser::parse($text);

        return new ScannerResult(
            status: $screening->status,
            rootCause: $screening->rootCauseAnalysis,
            suggestedNextSteps: $screening->suggestedNextSteps,
            technicalEvidence: $screening->rawTechnicalOutput
        );
    }
}
