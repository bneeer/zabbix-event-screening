<?php

namespace AI\Core\DTO;

use AI\Core\Exceptions\AiContractValidationException;

final readonly class ZabbixHost implements \JsonSerializable
{
    public function __construct(
        public string $hostId,
        public string $host,
        public string $name,
        public string $operatingSystem
    ) {
        if (trim($hostId) === '') {
            throw new AiContractValidationException('ZabbixHost hostId cannot be empty.');
        }

        if (trim($host) === '') {
            throw new AiContractValidationException('ZabbixHost host cannot be empty.');
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'hostid' => $this->hostId,
            'host' => $this->host,
            'name' => $this->name,
            'operating_system' => $this->operatingSystem,
        ];
    }
}
