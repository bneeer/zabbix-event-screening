<?php

namespace Tests\Unit;

use AI\Core\Security\CommandTokenizer;
use AI\Core\Security\CommandValidationException;
use AI\Core\Security\OperatingSystem;
use PHPUnit\Framework\TestCase;

class CommandTokenizerTest extends TestCase
{
    private CommandTokenizer $tokenizer;

    protected function setUp(): void
    {
        $this->tokenizer = new CommandTokenizer();
    }

    public function testTokenizeSimpleCommand(): void
    {
        $tokens = $this->tokenizer->tokenize("uptime", OperatingSystem::LINUX);
        $this->assertSame(["uptime"], $tokens);
    }

    public function testTokenizeCommandWithArgumentsAndQuotes(): void
    {
        $tokens = $this->tokenizer->tokenize('grep -i "disk error" /var/log/syslog', OperatingSystem::LINUX);
        $this->assertSame(['grep', '-i', 'disk error', '/var/log/syslog'], $tokens);

        $tokensWin = $this->tokenizer->tokenize("Get-Service -Name 'Spooler'", OperatingSystem::WINDOWS);
        $this->assertSame(['Get-Service', '-Name', 'Spooler'], $tokensWin);
    }

    public function testRejectsSemicolonChaining(): void
    {
        $this->expectException(CommandValidationException::class);
        $this->expectExceptionMessage("operator ';'");
        $this->tokenizer->tokenize("uptime; rm -rf /", OperatingSystem::LINUX);
    }

    public function testRejectsLogicalAndOperator(): void
    {
        $this->expectException(CommandValidationException::class);
        $this->expectExceptionMessage("operator '&'");
        $this->tokenizer->tokenize("uptime && reboot", OperatingSystem::LINUX);
    }

    public function testRejectsLogicalOrOperator(): void
    {
        $this->expectException(CommandValidationException::class);
        $this->expectExceptionMessage("operator '|'");
        $this->tokenizer->tokenize("uptime || hostname", OperatingSystem::LINUX);
    }

    public function testRejectsPipelineOperator(): void
    {
        $this->expectException(CommandValidationException::class);
        $this->expectExceptionMessage("Pipeline or OR operator '|'");
        $this->tokenizer->tokenize("cat /var/log/syslog | grep error", OperatingSystem::LINUX);
    }

    public function testRejectsOutputRedirection(): void
    {
        $this->expectException(CommandValidationException::class);
        $this->expectExceptionMessage("Redirection operator '>'");
        $this->tokenizer->tokenize("ps aux > /tmp/ps.txt", OperatingSystem::LINUX);
    }

    public function testRejectsInputRedirection(): void
    {
        $this->expectException(CommandValidationException::class);
        $this->expectExceptionMessage("Redirection operator '<'");
        $this->tokenizer->tokenize("cat < /etc/passwd", OperatingSystem::LINUX);
    }

    public function testRejectsCommandSubstitutionDollarParentheses(): void
    {
        $this->expectException(CommandValidationException::class);
        $this->expectExceptionMessage("Command substitution '$()'");
        $this->tokenizer->tokenize("echo $(whoami)", OperatingSystem::LINUX);
    }

    public function testRejectsBacktickSubstitution(): void
    {
        $this->expectException(CommandValidationException::class);
        $this->expectExceptionMessage("Backtick command substitution");
        $this->tokenizer->tokenize("echo `whoami`", OperatingSystem::LINUX);
    }

    public function testRejectsNewlineChaining(): void
    {
        $this->expectException(CommandValidationException::class);
        $this->expectExceptionMessage("Newline characters are forbidden");
        $this->tokenizer->tokenize("uptime\nreboot", OperatingSystem::LINUX);
    }

    public function testRejectsUnclosedQuotes(): void
    {
        $this->expectException(CommandValidationException::class);
        $this->expectExceptionMessage("Unclosed quote");
        $this->tokenizer->tokenize('grep -i "unclosed', OperatingSystem::LINUX);
    }

    public function testRejectsBackgroundExecution(): void
    {
        $this->expectException(CommandValidationException::class);
        $this->expectExceptionMessage("operator '&'");
        $this->tokenizer->tokenize("uptime &", OperatingSystem::LINUX);
    }
}
