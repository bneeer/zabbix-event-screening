<?php

namespace AI\Core\Security;

class CommandValidator
{
    private CommandTokenizer $tokenizer;

    public function __construct(?CommandTokenizer $tokenizer = null)
    {
        $this->tokenizer = $tokenizer ?? new CommandTokenizer();
    }

    /**
     * Validates a single command against an operating system policy.
     *
     * @param string $command
     * @param OperatingSystem|string $os
     * @return ParsedCommand
     * @throws CommandValidationException if the command is invalid or unsafe.
     */
    public function validate(string $command, OperatingSystem|string $os): ParsedCommand
    {
        $operatingSystem = is_string($os) ? OperatingSystem::fromString($os) : $os;

        $trimmed = trim($command);
        if ($trimmed === '') {
            throw new CommandValidationException($command, "Empty command provided", $operatingSystem);
        }

        // Tokenize and verify lexical safety
        $tokens = $this->tokenizer->tokenize($trimmed, $operatingSystem);

        $executable = $tokens[0];
        $arguments = array_slice($tokens, 1);

        $parsed = new ParsedCommand(
            raw: $trimmed,
            executable: $executable,
            arguments: $arguments,
            tokens: $tokens,
            operatingSystem: $operatingSystem
        );

        // Fetch policy for OS and validate
        $policy = CommandPolicy::forOs($operatingSystem);
        $policy->validate($parsed);

        return $parsed;
    }

    /**
     * Validates an array of commands against an operating system policy.
     *
     * @param array<string> $commands
     * @param OperatingSystem|string $os
     * @return array<ParsedCommand>
     * @throws CommandValidationException on the first invalid command.
     */
    public function validateAll(array $commands, OperatingSystem|string $os): array
    {
        if (empty($commands)) {
            throw new CommandValidationException(
                "",
                "No commands provided to validate",
                is_string($os) ? OperatingSystem::fromString($os) : $os
            );
        }

        $validated = [];
        foreach ($commands as $cmd) {
            $validated[] = $this->validate($cmd, $os);
        }
        return $validated;
    }

    /**
     * Validates a command against either Linux or Windows if OS is ambiguous,
     * ensuring it strictly conforms to at least one valid policy and violates none.
     *
     * @param string $command
     * @return ParsedCommand
     * @throws CommandValidationException
     */
    public function validateCrossPlatform(string $command): ParsedCommand
    {
        $trimmed = trim($command);
        if ($trimmed === '') {
            throw new CommandValidationException($command, "Empty command provided", OperatingSystem::UNKNOWN);
        }

        $exceptions = [];
        foreach ([OperatingSystem::LINUX, OperatingSystem::WINDOWS] as $candidateOs) {
            try {
                return $this->validate($trimmed, $candidateOs);
            } catch (CommandValidationException $e) {
                $exceptions[] = $e->getMessage();
            }
        }

        throw new CommandValidationException(
            $command,
            "Command failed validation across all supported OS policies: " . implode('; ', $exceptions),
            OperatingSystem::UNKNOWN
        );
    }

    public function isValid(string $command, OperatingSystem|string $os): bool
    {
        try {
            $this->validate($command, $os);
            return true;
        } catch (CommandValidationException) {
            return false;
        }
    }
}
