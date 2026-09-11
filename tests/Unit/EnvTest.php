<?php

declare(strict_types=1);

namespace Tests\Unit;

use AI\Core\Config\Env;
use AI\Core\Config\MissingConfigurationException;
use PHPUnit\Framework\TestCase;

class EnvTest extends TestCase
{
    protected function tearDown(): void
    {
        Env::clearOverrides();
        unset($_ENV['ENV_TEST_A'], $_SERVER['ENV_TEST_B']);
        putenv('ENV_TEST_C');
    }

    public function testResolvesFromEnvServerAndProcessInOrder(): void
    {
        $_ENV['ENV_TEST_A'] = 'from-env';
        $_SERVER['ENV_TEST_B'] = 'from-server';
        putenv('ENV_TEST_C=from-process');

        self::assertSame('from-env', Env::get('ENV_TEST_A'));
        self::assertSame('from-server', Env::get('ENV_TEST_B'));
        self::assertSame('from-process', Env::get('ENV_TEST_C'));
    }

    public function testEmptyStringIsTreatedAsUnset(): void
    {
        $_ENV['ENV_TEST_A'] = '';

        self::assertSame('fallback', Env::get('ENV_TEST_A', 'fallback'));
        self::assertNull(Env::get('ENV_TEST_A'));
    }

    public function testRequiredThrowsWhenMissing(): void
    {
        $this->expectException(MissingConfigurationException::class);
        Env::required('ENV_TEST_MISSING_' . uniqid());
    }

    public function testIntAndBoolParsing(): void
    {
        Env::set('ENV_TEST_INT', '42');
        Env::set('ENV_TEST_BOOL_YES', 'yes');
        Env::set('ENV_TEST_BOOL_ZERO', '0');

        self::assertSame(42, Env::int('ENV_TEST_INT', 1));
        self::assertSame(7, Env::int('ENV_TEST_INT_MISSING', 7));
        self::assertTrue(Env::bool('ENV_TEST_BOOL_YES', false));
        self::assertFalse(Env::bool('ENV_TEST_BOOL_ZERO', true));
        self::assertTrue(Env::bool('ENV_TEST_BOOL_MISSING', true));
    }

    public function testInvalidIntThrows(): void
    {
        Env::set('ENV_TEST_INT', 'abc');

        $this->expectException(MissingConfigurationException::class);
        Env::int('ENV_TEST_INT', 1);
    }

    public function testInvalidBoolThrows(): void
    {
        Env::set('ENV_TEST_BOOL', 'maybe');

        $this->expectException(MissingConfigurationException::class);
        Env::bool('ENV_TEST_BOOL', true);
    }

    public function testFirstOfReturnsFirstDefinedValue(): void
    {
        Env::set('ENV_TEST_SECOND', 'second');

        self::assertSame('second', Env::firstOf(['ENV_TEST_FIRST_MISSING', 'ENV_TEST_SECOND']));
        self::assertSame('dflt', Env::firstOf(['ENV_TEST_NONE_1', 'ENV_TEST_NONE_2'], 'dflt'));
    }
}
