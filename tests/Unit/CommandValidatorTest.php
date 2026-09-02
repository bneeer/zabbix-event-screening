<?php

namespace Tests\Unit;

use AI\Core\Security\CommandValidationException;
use AI\Core\Security\CommandValidator;
use AI\Core\Security\OperatingSystem;
use PHPUnit\Framework\TestCase;

class CommandValidatorTest extends TestCase
{
    private CommandValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CommandValidator();
    }

    public function testLinuxWindowsDifferences(): void
    {
        // Linux command on Linux is valid
        $this->assertTrue($this->validator->isValid("uptime", OperatingSystem::LINUX));
        // Windows cmdlet on Windows is valid
        $this->assertTrue($this->validator->isValid("Get-Service -Name Spooler", OperatingSystem::WINDOWS));

        // Linux-specific command on Windows policy is rejected
        $this->assertFalse($this->validator->isValid("systemctl status nginx", OperatingSystem::WINDOWS));
        // Windows cmdlet on Linux policy is rejected
        $this->assertFalse($this->validator->isValid("Get-Service -Name Spooler", OperatingSystem::LINUX));
    }

    public function testValidateAllSucceedsForValidList(): void
    {
        $commands = [
            "uptime",
            "df -h",
            "free -m"
        ];
        $parsed = $this->validator->validateAll($commands, OperatingSystem::LINUX);
        $this->assertCount(3, $parsed);
    }

    public function testValidateAllFailsIfAnyCommandIsDangerous(): void
    {
        $commands = [
            "uptime",
            "rm -rf /",
            "df -h"
        ];

        $this->expectException(CommandValidationException::class);
        $this->validator->validateAll($commands, OperatingSystem::LINUX);
    }

    public function testValidateCrossPlatformWithValidCommands(): void
    {
        $linuxParsed = $this->validator->validateCrossPlatform("uptime");
        $this->assertSame("uptime", $linuxParsed->getExecutable());

        $winParsed = $this->validator->validateCrossPlatform("Get-Service -Name Spooler");
        $this->assertSame("Get-Service", $winParsed->getExecutable());
    }

    public function testValidateCrossPlatformFailsForDangerousCommands(): void
    {
        $this->expectException(CommandValidationException::class);
        $this->validator->validateCrossPlatform("rm -rf /");
    }

    public function testEmptyCommandThrowsException(): void
    {
        $this->expectException(CommandValidationException::class);
        $this->validator->validate("   ", OperatingSystem::LINUX);
    }
}
