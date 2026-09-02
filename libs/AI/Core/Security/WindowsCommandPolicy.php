<?php

namespace AI\Core\Security;

class WindowsCommandPolicy implements CommandPolicyInterface
{
    /**
     * Whitelist of allowed Windows cmdlets and executables.
     */
    private const ALLOWED_EXECUTABLES = [
        'get-service',
        'get-process',
        'get-content',
        'get-eventlog',
        'get-winevent',
        'get-ciminstance',
        'get-wmiobject',
        'get-netipaddress',
        'get-nettcpconnection',
        'get-netroute',
        'get-netadapter',
        'get-netudpendpoint',
        'test-netconnection',
        'dir',
        'type',
        'findstr',
        'tasklist',
        'systeminfo',
        'wmic',
        'ipconfig',
        'netstat',
        'qwinsta',
        'quser',
        'hostname',
        'whoami',
        'uptime',
        'get-uptime',
        'get-hotfix',
        'get-item',
        'get-childitem',
        'get-volume',
        'get-disk',
        'get-partition',
        'get-computerinfo',
        'get-counter',
        'powershell',
        'powershell.exe',
        'pwsh',
        'pwsh.exe',
    ];

    /**
     * Explicitly forbidden Windows executables, cmdlets, and scripts.
     */
    private const FORBIDDEN_EXECUTABLES = [
        'remove-item',
        'stop-service',
        'restart-service',
        'start-service',
        'set-service',
        'suspend-service',
        'resume-service',
        'restart-computer',
        'stop-computer',
        'del',
        'erase',
        'rmdir',
        'rd',
        'format-volume',
        'set-executionpolicy',
        'invoke-expression',
        'iex',
        'invoke-webrequest',
        'iwr',
        'curl',
        'wget',
        'start-process',
        'new-item',
        'copy-item',
        'move-item',
        'clear-eventlog',
        'stop-process',
        'kill',
        'taskkill',
        'sc',
        'reg',
        'takeown',
        'icacls',
        'format',
        'diskpart',
        'cmd',
        'bash',
        'sh',
        'python',
        'perl',
        'ruby',
    ];

    /**
     * Sensitive paths and files that must never be read or accessed.
     */
    private const SENSITIVE_PATHS = [
        '\\sam',
        '/sam',
        '\\system32\\config',
        '/system32/config',
        '\\security',
        '/security',
        'ntds.dit',
        'id_rsa',
        'id_ecdsa',
        'id_ed25519',
        '.ssh',
        'unattend.xml',
        'sysprep.inf',
    ];

    public function getOperatingSystem(): OperatingSystem
    {
        return OperatingSystem::WINDOWS;
    }

    public function isAllowedExecutable(string $executable): bool
    {
        $normalized = strtolower(preg_replace('/\.exe$/i', '', basename(str_replace('\\', '/', $executable))));
        return in_array($normalized, self::ALLOWED_EXECUTABLES, true);
    }

    public function validate(ParsedCommand $command): void
    {
        $executable = $command->getNormalizedExecutable();

        if (in_array($executable, self::FORBIDDEN_EXECUTABLES, true)) {
            throw new CommandValidationException(
                $command->getRaw(),
                "Executable or cmdlet '{$executable}' is explicitly forbidden",
                OperatingSystem::WINDOWS
            );
        }

        if (!$this->isAllowedExecutable($executable)) {
            throw new CommandValidationException(
                $command->getRaw(),
                "Executable or cmdlet '{$executable}' is not in the allowed Windows diagnostic commands list",
                OperatingSystem::WINDOWS
            );
        }

        $arguments = $command->getArguments();

        // Check for sensitive paths
        foreach ($arguments as $arg) {
            foreach (self::SENSITIVE_PATHS as $sensitivePath) {
                if (stripos($arg, $sensitivePath) !== false) {
                    throw new CommandValidationException(
                        $command->getRaw(),
                        "Access to sensitive path or file '{$sensitivePath}' is forbidden",
                        OperatingSystem::WINDOWS
                    );
                }
            }
        }

        // Specific command validations
        $this->validateExecutableSpecifics($executable, $arguments, $command->getRaw());
    }

    private function validateExecutableSpecifics(string $executable, array $arguments, string $rawCommand): void
    {
        switch ($executable) {
            case 'powershell':
            case 'powershell.exe':
            case 'pwsh':
            case 'pwsh.exe':
                $this->validatePowerShellInvocation($arguments, $rawCommand);
                break;

            case 'wmic':
                $allowedVerbs = ['get', 'list', '/?', 'help'];
                $forbiddenVerbs = ['delete', 'call', 'create', 'set'];

                $hasAllowedVerb = false;
                foreach ($arguments as $arg) {
                    $lowerArg = strtolower($arg);
                    if (in_array($lowerArg, $forbiddenVerbs, true)) {
                        throw new CommandValidationException(
                            $rawCommand,
                            "State-changing wmic verb '{$arg}' is strictly forbidden",
                            OperatingSystem::WINDOWS
                        );
                    }
                    if (in_array($lowerArg, $allowedVerbs, true)) {
                        $hasAllowedVerb = true;
                    }
                }

                if (!$hasAllowedVerb && !empty($arguments)) {
                    throw new CommandValidationException(
                        $rawCommand,
                        "wmic command must include a read-only query verb (get or list)",
                        OperatingSystem::WINDOWS
                    );
                }
                break;
        }
    }

    private function validatePowerShellInvocation(array $arguments, string $rawCommand): void
    {
        $forbiddenFlags = [
            '-encodedcommand', '-e', '-enc',
            '-file', '-f',
            '-windowstyle',
            '-executionpolicy bypass',
        ];

        $commandPayload = null;
        $count = count($arguments);

        for ($i = 0; $i < $count; $i++) {
            $arg = $arguments[$i];
            $lower = strtolower($arg);

            foreach ($forbiddenFlags as $flag) {
                if ($lower === $flag || str_starts_with($lower, $flag)) {
                    throw new CommandValidationException(
                        $rawCommand,
                        "Dangerous PowerShell argument '{$arg}' is forbidden",
                        OperatingSystem::WINDOWS
                    );
                }
            }

            if ($lower === '-command' || $lower === '-c' || $lower === '/c' || $lower === '/command') {
                if (isset($arguments[$i + 1])) {
                    $commandPayload = $arguments[$i + 1];
                    // If multiple tokens follow, combine them
                    if ($i + 2 < $count) {
                        $commandPayload = implode(' ', array_slice($arguments, $i + 1));
                    }
                    break;
                }
            }
        }

        if ($commandPayload === null) {
            throw new CommandValidationException(
                $rawCommand,
                "PowerShell invocation must specify a valid -Command argument",
                OperatingSystem::WINDOWS
            );
        }

        // Recursively validate inner command
        $tokenizer = new CommandTokenizer();
        $innerTokens = $tokenizer->tokenize($commandPayload, OperatingSystem::WINDOWS);
        $innerExec = $innerTokens[0];
        $innerArgs = array_slice($innerTokens, 1);

        $innerNormalized = strtolower(preg_replace('/\.exe$/i', '', basename(str_replace('\\', '/', $innerExec))));
        if ($innerNormalized === 'powershell' || $innerNormalized === 'pwsh') {
            throw new CommandValidationException(
                $rawCommand,
                "Nested PowerShell invocations are forbidden",
                OperatingSystem::WINDOWS
            );
        }

        $innerParsed = new ParsedCommand(
            raw: $commandPayload,
            executable: $innerExec,
            arguments: $innerArgs,
            tokens: $innerTokens,
            operatingSystem: OperatingSystem::WINDOWS
        );

        $this->validate($innerParsed);
    }
}
