<?php

namespace AI\Core\Security;

interface CommandPolicyInterface
{
    public function getOperatingSystem(): OperatingSystem;

    public function isAllowedExecutable(string $executable): bool;

    /**
     * Validates parsed command tokens and arguments against this OS policy.
     *
     * @param ParsedCommand $command
     * @throws CommandValidationException
     */
    public function validate(ParsedCommand $command): void;
}
