<?php

namespace AI\Core\Security;

class LinuxCommandPolicy implements CommandPolicyInterface
{
    /**
     * Whitelist of allowed Linux diagnostic executables.
     */
    private const ALLOWED_EXECUTABLES = [
        'cat',
        'grep',
        'egrep',
        'fgrep',
        'awk',
        'sed',
        'head',
        'tail',
        'ls',
        'df',
        'du',
        'ps',
        'top',
        'uptime',
        'free',
        'netstat',
        'ss',
        'lsof',
        'uname',
        'hostname',
        'journalctl',
        'systemctl',
        'vmstat',
        'iostat',
        'mpstat',
        'sar',
        'dmesg',
        'ip',
        'ifconfig',
        'ping',
        'traceroute',
        'tracepath',
        'dig',
        'nslookup',
        'host',
        'find',
        'wc',
        'cut',
        'sort',
        'uniq',
        'env',
        'printenv',
        'id',
        'whoami',
        'who',
        'w',
        'last',
        'lastb',
        'timedatectl',
        'lscpu',
        'lsblk',
        'lspci',
        'lsusb',
        'lsmem',
    ];

    /**
     * Explicitly forbidden executables and interpreters.
     */
    private const FORBIDDEN_EXECUTABLES = [
        'rm',
        'rmdir',
        'reboot',
        'shutdown',
        'halt',
        'poweroff',
        'init',
        'telinit',
        'sudo',
        'su',
        'doas',
        'pkexec',
        'bash',
        'sh',
        'zsh',
        'ksh',
        'csh',
        'tcsh',
        'dash',
        'ash',
        'python',
        'python2',
        'python3',
        'perl',
        'ruby',
        'php',
        'node',
        'nodejs',
        'lua',
        'tclsh',
        'curl',
        'wget',
        'nc',
        'ncat',
        'netcat',
        'socat',
        'dd',
        'mkfs',
        'fdisk',
        'parted',
        'chmod',
        'chown',
        'chgrp',
        'useradd',
        'userdel',
        'usermod',
        'passwd',
        'kill',
        'pkill',
        'killall',
        'iptables',
        'nft',
        'ufw',
        'firewall-cmd',
        'crontab',
        'at',
        'batch',
    ];

    /**
     * Sensitive paths and files that must never be read or accessed.
     */
    private const SENSITIVE_PATHS = [
        '/etc/shadow',
        '/etc/gshadow',
        '/etc/sudoers',
        '/etc/sudoers.d',
        '/root/.ssh',
        'id_rsa',
        'id_ecdsa',
        'id_ed25519',
        'authorized_keys',
        '/dev/mem',
        '/dev/kmem',
        '/proc/kcore',
    ];

    public function getOperatingSystem(): OperatingSystem
    {
        return OperatingSystem::LINUX;
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
                "Executable '{$executable}' is explicitly forbidden",
                OperatingSystem::LINUX
            );
        }

        if (!$this->isAllowedExecutable($executable)) {
            throw new CommandValidationException(
                $command->getRaw(),
                "Executable '{$executable}' is not in the allowed Linux diagnostic commands list",
                OperatingSystem::LINUX
            );
        }

        $arguments = $command->getArguments();

        // Check for sensitive paths
        foreach ($arguments as $arg) {
            foreach (self::SENSITIVE_PATHS as $sensitivePath) {
                if (stripos($arg, $sensitivePath) !== false) {
                    throw new CommandValidationException(
                        $command->getRaw(),
                        "Access to sensitive path '{$sensitivePath}' is forbidden",
                        OperatingSystem::LINUX
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
            case 'systemctl':
                $allowedSubcommands = [
                    'status', 'is-active', 'is-failed', 'is-enabled',
                    'list-units', 'list-unit-files', 'list-sockets', 'list-timers',
                    'show', 'cat'
                ];
                $forbiddenSubcommands = [
                    'restart', 'stop', 'start', 'reload', 'disable', 'enable',
                    'mask', 'unmask', 'edit', 'daemon-reload', 'reboot',
                    'poweroff', 'halt', 'kill', 'reset-failed', 'isolate',
                    'suspend', 'hibernate'
                ];

                $subcommandFound = false;
                foreach ($arguments as $arg) {
                    $lowerArg = strtolower($arg);
                    if (in_array($lowerArg, $forbiddenSubcommands, true)) {
                        throw new CommandValidationException(
                            $rawCommand,
                            "State-changing systemctl subcommand '{$arg}' is strictly forbidden",
                            OperatingSystem::LINUX
                        );
                    }
                    if (in_array($lowerArg, $allowedSubcommands, true)) {
                        $subcommandFound = true;
                    }
                }

                if (!$subcommandFound && !empty($arguments)) {
                    // Check if non-flag arguments are specified without a known read-only subcommand
                    $nonFlags = array_values(array_filter($arguments, fn($a) => !str_starts_with($a, '-')));
                    if (!empty($nonFlags) && !in_array(strtolower($nonFlags[0]), $allowedSubcommands, true)) {
                        throw new CommandValidationException(
                            $rawCommand,
                            "Unrecognized or unsafe systemctl action '{$nonFlags[0]}'",
                            OperatingSystem::LINUX
                        );
                    }
                }
                break;

            case 'sed':
                foreach ($arguments as $arg) {
                    if ($arg === '-i' || str_starts_with($arg, '-i') || str_starts_with($arg, '--in-place')) {
                        throw new CommandValidationException(
                            $rawCommand,
                            "In-place file editing flag '{$arg}' in sed is forbidden",
                            OperatingSystem::LINUX
                        );
                    }
                    if (preg_match('/[wW]\s+[^\s]+/', $arg)) {
                        throw new CommandValidationException(
                            $rawCommand,
                            "File write action in sed expression is forbidden",
                            OperatingSystem::LINUX
                        );
                    }
                }
                break;

            case 'find':
                $forbiddenFindArgs = ['-delete', '-exec', '-execdir', '-ok', '-okdir', '-fprint', '-fprint0', '-fprintf'];
                foreach ($arguments as $arg) {
                    $lower = strtolower($arg);
                    if (in_array($lower, $forbiddenFindArgs, true)) {
                        throw new CommandValidationException(
                            $rawCommand,
                            "Dangerous find argument '{$arg}' is forbidden",
                            OperatingSystem::LINUX
                        );
                    }
                }
                break;

            case 'journalctl':
                $forbiddenJournalArgs = ['--flush', '--rotate', '--vacuum-size', '--vacuum-time', '--vacuum-files'];
                foreach ($arguments as $arg) {
                    $lower = strtolower($arg);
                    if (in_array($lower, $forbiddenJournalArgs, true)) {
                        throw new CommandValidationException(
                            $rawCommand,
                            "Journal modification argument '{$arg}' is forbidden",
                            OperatingSystem::LINUX
                        );
                    }
                }
                break;
        }
    }
}
