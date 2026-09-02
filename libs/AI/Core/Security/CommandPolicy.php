<?php

namespace AI\Core\Security;

class CommandPolicy
{
    private static ?LinuxCommandPolicy $linuxPolicy = null;
    private static ?WindowsCommandPolicy $windowsPolicy = null;

    public static function forOs(OperatingSystem $os): CommandPolicyInterface
    {
        return match ($os) {
            OperatingSystem::WINDOWS => self::getWindowsPolicy(),
            OperatingSystem::LINUX => self::getLinuxPolicy(),
            OperatingSystem::UNKNOWN => throw new CommandValidationException(
                "",
                "Cannot validate command for UNKNOWN operating system. Specific OS policy (Linux/Windows) is required.",
                $os
            ),
        };
    }

    public static function getLinuxPolicy(): LinuxCommandPolicy
    {
        return self::$linuxPolicy ??= new LinuxCommandPolicy();
    }

    public static function getWindowsPolicy(): WindowsCommandPolicy
    {
        return self::$windowsPolicy ??= new WindowsCommandPolicy();
    }
}
