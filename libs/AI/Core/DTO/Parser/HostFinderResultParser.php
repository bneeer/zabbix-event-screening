<?php

namespace AI\Core\DTO\Parser;

use AI\Core\DTO\ZabbixHost;
use AI\Core\DTO\ZabbixHostMatchResult;
use AI\Core\Exceptions\AiContractValidationException;

final class HostFinderResultParser
{
    /**
     * @param string|array<string, mixed> $input
     * @return ZabbixHostMatchResult
     * @throws AiContractValidationException
     */
    public static function parse(string|array $input): ZabbixHostMatchResult
    {
        $data = is_array($input) ? $input : JsonHelper::extractJsonArray($input);

        $hostnames = [];
        if (array_key_exists('hostnames', $data)) {
            if (!is_array($data['hostnames'])) {
                throw new AiContractValidationException(sprintf(
                    'Field "hostnames" must be an array, got %s.',
                    gettype($data['hostnames'])
                ));
            }
            foreach ($data['hostnames'] as $index => $hn) {
                if (!is_string($hn)) {
                    throw new AiContractValidationException(sprintf(
                        'Each item in "hostnames" must be a string, got %s at index %s.',
                        gettype($hn),
                        (string)$index
                    ));
                }
                $hostnames[] = $hn;
            }
        }

        $hosts = [];
        if (array_key_exists('hosts', $data)) {
            if (!is_array($data['hosts'])) {
                throw new AiContractValidationException(sprintf(
                    'Field "hosts" must be an array, got %s.',
                    gettype($data['hosts'])
                ));
            }

            foreach ($data['hosts'] as $index => $hostData) {
                if (!is_array($hostData)) {
                    throw new AiContractValidationException(sprintf(
                        'Each host in "hosts" must be an object/array, got %s at index %s.',
                        gettype($hostData),
                        (string)$index
                    ));
                }

                if (!isset($hostData['hostid']) || (!is_string($hostData['hostid']) && !is_int($hostData['hostid']))) {
                    throw new AiContractValidationException(sprintf(
                        'Missing or invalid "hostid" for host at index %s.',
                        (string)$index
                    ));
                }

                $hostId = (string)$hostData['hostid'];
                $host = isset($hostData['host']) && is_string($hostData['host']) ? $hostData['host'] : $hostId;
                $name = isset($hostData['name']) && is_string($hostData['name']) ? $hostData['name'] : $host;
                $os = isset($hostData['operating_system']) && is_string($hostData['operating_system']) ? $hostData['operating_system'] : 'Linux';

                $hosts[] = new ZabbixHost(
                    hostId: $hostId,
                    host: $host,
                    name: $name,
                    operatingSystem: $os
                );
            }
        }

        return new ZabbixHostMatchResult(
            hostnames: $hostnames,
            hosts: $hosts
        );
    }
}
