<?php

namespace AI\Core\DTO;

use AI\Core\Exceptions\AiContractValidationException;

final readonly class ZabbixHostMatchResult implements \JsonSerializable
{
    /**
     * @param array<string> $hostnames
     * @param array<ZabbixHost> $hosts
     */
    public function __construct(
        public array $hostnames = [],
        public array $hosts = []
    ) {
        foreach ($hostnames as $index => $hostname) {
            if (!is_string($hostname)) {
                throw new AiContractValidationException(sprintf(
                    'Hostnames must be an array of strings, %s found at index %d.',
                    get_debug_type($hostname),
                    $index
                ));
            }
        }

        foreach ($hosts as $index => $host) {
            if (!$host instanceof ZabbixHost) {
                throw new AiContractValidationException(sprintf(
                    'Hosts must contain instances of %s, %s found at index %d.',
                    ZabbixHost::class,
                    get_debug_type($host),
                    $index
                ));
            }
        }
    }

    /**
     * @return array<string, string> Map of hostid => operating_system
     */
    public function getHostIdToOsMap(): array
    {
        $map = [];
        foreach ($this->hosts as $host) {
            $map[$host->hostId] = $host->operatingSystem;
        }
        return $map;
    }

    public function isEmpty(): bool
    {
        return empty($this->hosts);
    }

    public function jsonSerialize(): array
    {
        return [
            'hostnames' => $this->hostnames,
            'hosts' => array_map(fn(ZabbixHost $h) => $h->jsonSerialize(), $this->hosts),
        ];
    }
}
