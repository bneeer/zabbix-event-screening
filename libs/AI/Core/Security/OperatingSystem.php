<?php

namespace AI\Core\Security;

enum OperatingSystem: string
{
    case LINUX = 'Linux';
    case WINDOWS = 'Windows';
    case UNKNOWN = 'UNKNOWN';

    public static function fromString(?string $os): self
    {
        if ($os === null || trim($os) === '') {
            return self::UNKNOWN;
        }

        $normalized = strtolower(trim($os));

        if (str_contains($normalized, 'win') || str_contains($normalized, 'windows')) {
            return self::WINDOWS;
        }

        if (
            str_contains($normalized, 'linux') ||
            str_contains($normalized, 'rhel') ||
            str_contains($normalized, 'red hat') ||
            str_contains($normalized, 'centos') ||
            str_contains($normalized, 'ubuntu') ||
            str_contains($normalized, 'debian') ||
            str_contains($normalized, 'suse') ||
            str_contains($normalized, 'sles') ||
            str_contains($normalized, 'oracle') ||
            str_contains($normalized, 'unix') ||
            str_contains($normalized, 'lnx')
        ) {
            return self::LINUX;
        }

        return self::UNKNOWN;
    }
}
