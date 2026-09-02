<?php

namespace AI\Core\DTO;

enum ScreeningStatus: string
{
    case SUCCESS = '######SUCCESS########';
    case FAILED_AUTHENTICATION = '######FAILED:AUTHENTICATION########';
    case FAILED_NETWORK_ERROR = '######FAILED:NETWORK_ERROR########';
    case FAILED_AGENT_ERROR = '######FAILED:AGENT_ERROR########';
    case SKIPPING_NO_HOSTS = '######SKIPPING: NO HOSTS MATCHED########';

    public static function tryFromRaw(string $value): ?self
    {
        $trimmed = trim($value);
        foreach (self::cases() as $case) {
            if ($case->value === $trimmed || str_contains($trimmed, $case->name) || str_contains($trimmed, $case->value)) {
                return $case;
            }
        }
        return null;
    }

    public function isSuccess(): bool
    {
        return $this === self::SUCCESS;
    }
}
