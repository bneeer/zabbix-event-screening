<?php

declare(strict_types=1);

namespace AI\Infrastructure\Logging;

/**
 * Minimal structured logger writing to STDOUT/STDERR (container-friendly).
 * Supports plain text or JSON lines, filtered by minimum level.
 */
final class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    private const LEVELS = [self::DEBUG => 0, self::INFO => 1, self::WARNING => 2, self::ERROR => 3];

    /** @var resource */
    private $stdout;
    /** @var resource */
    private $stderr;

    public function __construct(
        private readonly string $minLevel = self::INFO,
        private readonly string $format = 'text',
        private readonly string $channel = 'app',
        $stdout = null,
        $stderr = null
    ) {
        if (!isset(self::LEVELS[$minLevel])) {
            throw new \InvalidArgumentException("Unknown log level '{$minLevel}'.");
        }

        $this->stdout = $stdout ?? fopen('php://stdout', 'w');
        $this->stderr = $stderr ?? fopen('php://stderr', 'w');
    }

    public static function fromEnvironment(string $channel = 'app'): self
    {
        return new self(
            minLevel: defined('LOG_LEVEL') ? LOG_LEVEL : self::INFO,
            format: defined('LOG_FORMAT') ? LOG_FORMAT : 'text',
            channel: $channel
        );
    }

    public function withChannel(string $channel): self
    {
        return new self($this->minLevel, $this->format, $channel, $this->stdout, $this->stderr);
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (self::LEVELS[$level] < self::LEVELS[$this->minLevel]) {
            return;
        }

        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339);

        if ($this->format === 'json') {
            $line = json_encode(
                ['ts' => $timestamp, 'level' => $level, 'channel' => $this->channel, 'msg' => $message, 'ctx' => $context === [] ? new \stdClass() : $context],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
        } else {
            $ctx = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $line = sprintf('[%s] %s.%s: %s%s', $timestamp, $this->channel, strtoupper($level), $message, $ctx);
        }

        $stream = self::LEVELS[$level] >= self::LEVELS[self::WARNING] ? $this->stderr : $this->stdout;
        fwrite($stream, $line . PHP_EOL);
    }
}
