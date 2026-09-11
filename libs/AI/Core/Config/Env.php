<?php

declare(strict_types=1);

namespace AI\Core\Config;

/**
 * Unified accessor for environment variables.
 *
 * Resolution order: $_ENV, $_SERVER, getenv(). This makes the application behave the
 * same whether configuration comes from a local .env file (vlucas/phpdotenv) or from
 * real process environment variables injected by Docker / Kubernetes / systemd.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $overrides = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$overrides)) {
            return self::$overrides[$key];
        }

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;

        if ($value === null) {
            $fromProcess = getenv($key);
            $value = $fromProcess === false ? null : $fromProcess;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return is_string($value) ? $value : (string)$value;
    }

    public static function required(string $key): string
    {
        $value = self::get($key);

        if ($value === null) {
            throw new MissingConfigurationException("Required environment variable '{$key}' is not set.");
        }

        return $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        if (!is_numeric($value)) {
            throw new MissingConfigurationException("Environment variable '{$key}' must be an integer, got '{$value}'.");
        }

        return (int)$value;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            throw new MissingConfigurationException("Environment variable '{$key}' must be a boolean (true/false/1/0/yes/no), got '{$value}'.");
        }

        return $parsed;
    }

    /**
     * Returns the first non-empty value among the given keys.
     *
     * @param string[] $keys
     */
    public static function firstOf(array $keys, ?string $default = null): ?string
    {
        foreach ($keys as $key) {
            $value = self::get($key);
            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Overrides a value for the current process (used by tests and CLI flags).
     */
    public static function set(string $key, ?string $value): void
    {
        if ($value === null) {
            unset(self::$overrides[$key]);
            return;
        }

        self::$overrides[$key] = $value;
    }

    public static function clearOverrides(): void
    {
        self::$overrides = [];
    }
}
