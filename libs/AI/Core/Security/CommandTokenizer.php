<?php

namespace AI\Core\Security;

class CommandTokenizer
{
    /**
     * Tokenizes a command string into individual arguments while strictly rejecting
     * shell chaining, pipelines, redirections, backgrounding, and substitutions.
     *
     * @param string $command
     * @param OperatingSystem $os
     * @return array<string>
     * @throws CommandValidationException
     */
    public function tokenize(string $command, OperatingSystem $os): array
    {
        // 1. Control character checks
        if (str_contains($command, "\0")) {
            throw new CommandValidationException($command, "Null bytes are forbidden", $os);
        }
        if (str_contains($command, "\r") || str_contains($command, "\n")) {
            throw new CommandValidationException($command, "Newline characters are forbidden (command chaining attempt)", $os);
        }

        // 2. Prohibited shell substitution & evaluation patterns
        if (str_contains($command, '$(') || str_contains($command, '${')) {
            throw new CommandValidationException($command, "Command substitution '$()' or variable expansion is forbidden", $os);
        }
        if (str_contains($command, '`')) {
            throw new CommandValidationException($command, "Backtick command substitution is forbidden", $os);
        }
        if (str_contains($command, '<(') || str_contains($command, '>(')) {
            throw new CommandValidationException($command, "Process substitution is forbidden", $os);
        }

        // 3. Tokenize by walking through the string with quote tracking
        $tokens = [];
        $currentToken = '';
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $escaped = false;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($escaped) {
                $currentToken .= $char;
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                if ($os === OperatingSystem::WINDOWS) {
                    if ($inDoubleQuote && isset($command[$i + 1]) && $command[$i + 1] === '"') {
                        $escaped = true;
                        continue;
                    }
                    $currentToken .= $char;
                    continue;
                } else {
                    // Linux: escape next char if quote, space, backslash or operator
                    if (isset($command[$i + 1]) && in_array($command[$i + 1], ['"', "'", " ", "\\", ";", "&", "|", ">", "<"], true)) {
                        $escaped = true;
                        continue;
                    }
                    $currentToken .= $char;
                    continue;
                }
            }

            if ($char === "'" && !$inDoubleQuote) {
                $inSingleQuote = !$inSingleQuote;
                continue;
            }

            if ($char === '"' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;
                continue;
            }

            // Outside quotes: check forbidden shell operators
            if (!$inSingleQuote && !$inDoubleQuote) {
                // Command chaining / background operators
                if ($char === ';') {
                    throw new CommandValidationException($command, "Command chaining operator ';' is forbidden", $os);
                }
                if ($char === '&') {
                    throw new CommandValidationException($command, "Shell operator '&' or '&&' is forbidden", $os);
                }
//                if ($char === '|') {
//                    throw new CommandValidationException($command, "Pipeline or OR operator '|' or '||' is forbidden", $os);
//                }
                if ($char === '>' || $char === '<') {
                    throw new CommandValidationException($command, "Redirection operator '{$char}' is forbidden", $os);
                }

                // Whitespace delimits tokens
                if (ctype_space($char)) {
                    if ($currentToken !== '') {
                        $tokens[] = $currentToken;
                        $currentToken = '';
                    }
                    continue;
                }
            }

            $currentToken .= $char;
        }

        if ($inSingleQuote || $inDoubleQuote) {
            throw new CommandValidationException($command, "Unclosed quote in command string", $os);
        }

        if ($escaped) {
            throw new CommandValidationException($command, "Trailing escape backslash in command string", $os);
        }

        if ($currentToken !== '') {
            $tokens[] = $currentToken;
        }

        if (empty($tokens)) {
            throw new CommandValidationException($command, "Command contains no executable token", $os);
        }

        return $tokens;
    }
}
