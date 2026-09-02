<?php

namespace AI\Core\Security;

class ParsedCommand
{
    /**
     * @param string $raw
     * @param string $executable
     * @param array<string> $arguments
     * @param array<string> $tokens
     * @param OperatingSystem $operatingSystem
     */
    public function __construct(
        private string $raw,
        private string $executable,
        private array $arguments,
        private array $tokens,
        private OperatingSystem $operatingSystem
    ) {}

    public function getRaw(): string
    {
        return $this->raw;
    }

    public function getExecutable(): string
    {
        return $this->executable;
    }

    public function getNormalizedExecutable(): string
    {
        $basename = basename(str_replace('\\', '/', $this->executable));
        return strtolower(preg_replace('/\.exe$/i', '', $basename));
    }

    /**
     * @return array<string>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @return array<string>
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }

    public function getOperatingSystem(): OperatingSystem
    {
        return $this->operatingSystem;
    }
}
