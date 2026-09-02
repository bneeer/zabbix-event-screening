<?php

namespace Zabbix;

enum ExecutionType: int
{
    case AGENT = 0;
    case SERVER = 1;
    case SSH = 2;
    case TELNET = 3;
    case IPMI = 4;
    case WEBHOOK = 5;

    /**
     * Resolves an ExecutionType from an integer, string, or ExecutionType instance.
     *
     * @param int|string|self|null $value
     * @throws \InvalidArgumentException
     */
    public static function fromValue(int|string|self|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            throw new \InvalidArgumentException("Execution type cannot be null or empty");
        }

        if (is_numeric($value)) {
            $intVal = (int) $value;
            $enum = self::tryFrom($intVal);
            if ($enum !== null) {
                return $enum;
            }
        }

        if (is_string($value)) {
            $normalized = strtoupper(trim($value));
            foreach (self::cases() as $case) {
                if ($case->name === $normalized) {
                    return $case;
                }
            }
        }

        throw new \InvalidArgumentException("Invalid execution type: '{$value}'");
    }

    /**
     * Attempts to resolve an ExecutionType, returning null on failure.
     */
    public static function tryFromValue(int|string|self|null $value): ?self
    {
        try {
            return self::fromValue($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Checks if the execution type is supported for remote diagnostic command execution.
     */
    public function isSupported(): bool
    {
        return match ($this) {
            self::AGENT, self::SSH, self::TELNET => true,
            default => false,
        };
    }

    /**
     * Returns human-readable label for the execution type.
     */
    public function label(): string
    {
        return match ($this) {
            self::AGENT => 'Agent (0)',
            self::SERVER => 'Server (1)',
            self::SSH => 'SSH (2)',
            self::TELNET => 'Telnet (3)',
            self::IPMI => 'IPMI (4)',
            self::WEBHOOK => 'Webhook (5)',
        };
    }
}
