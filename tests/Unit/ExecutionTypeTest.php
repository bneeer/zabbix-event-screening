<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zabbix\ExecutionType;

class ExecutionTypeTest extends TestCase
{
    public function testFromValueWithEnum(): void
    {
        $this->assertSame(ExecutionType::AGENT, ExecutionType::fromValue(ExecutionType::AGENT));
        $this->assertSame(ExecutionType::SSH, ExecutionType::fromValue(ExecutionType::SSH));
    }

    public function testFromValueWithInts(): void
    {
        $this->assertSame(ExecutionType::AGENT, ExecutionType::fromValue(0));
        $this->assertSame(ExecutionType::SERVER, ExecutionType::fromValue(1));
        $this->assertSame(ExecutionType::SSH, ExecutionType::fromValue(2));
        $this->assertSame(ExecutionType::TELNET, ExecutionType::fromValue(3));
        $this->assertSame(ExecutionType::IPMI, ExecutionType::fromValue(4));
        $this->assertSame(ExecutionType::WEBHOOK, ExecutionType::fromValue(5));
    }

    public function testFromValueWithNumericStrings(): void
    {
        $this->assertSame(ExecutionType::AGENT, ExecutionType::fromValue("0"));
        $this->assertSame(ExecutionType::SSH, ExecutionType::fromValue("2"));
        $this->assertSame(ExecutionType::TELNET, ExecutionType::fromValue("3"));
    }

    public function testFromValueWithNameStrings(): void
    {
        $this->assertSame(ExecutionType::AGENT, ExecutionType::fromValue("agent"));
        $this->assertSame(ExecutionType::SSH, ExecutionType::fromValue("SSH"));
        $this->assertSame(ExecutionType::TELNET, ExecutionType::fromValue("telnet"));
    }

    public function testFromValueWithInvalidValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExecutionType::fromValue(99);
    }

    public function testFromValueWithNullThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExecutionType::fromValue(null);
    }

    public function testTryFromValue(): void
    {
        $this->assertSame(ExecutionType::AGENT, ExecutionType::tryFromValue(0));
        $this->assertSame(ExecutionType::SSH, ExecutionType::tryFromValue('2'));
        $this->assertNull(ExecutionType::tryFromValue('invalid'));
        $this->assertNull(ExecutionType::tryFromValue(99));
        $this->assertNull(ExecutionType::tryFromValue(null));
    }

    public function testIsSupported(): void
    {
        $this->assertTrue(ExecutionType::AGENT->isSupported());
        $this->assertTrue(ExecutionType::SSH->isSupported());
        $this->assertTrue(ExecutionType::TELNET->isSupported());

        $this->assertFalse(ExecutionType::SERVER->isSupported());
        $this->assertFalse(ExecutionType::IPMI->isSupported());
        $this->assertFalse(ExecutionType::WEBHOOK->isSupported());
    }

    public function testLabel(): void
    {
        $this->assertSame('Agent (0)', ExecutionType::AGENT->label());
        $this->assertSame('SSH (2)', ExecutionType::SSH->label());
        $this->assertSame('Telnet (3)', ExecutionType::TELNET->label());
    }
}
