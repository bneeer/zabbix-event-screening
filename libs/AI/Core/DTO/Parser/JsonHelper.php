<?php

namespace AI\Core\DTO\Parser;

use AI\Core\Exceptions\AiContractValidationException;

final class JsonHelper
{
    /**
     * Extracts and safely parses JSON from an LLM response string,
     * stripping potential markdown code fences (```json ... ```).
     *
     * @param string $rawInput
     * @return array<string, mixed>
     * @throws AiContractValidationException
     */
    public static function extractJsonArray(string $rawInput): array
    {
        $trimmed = trim($rawInput);

        if ($trimmed === '') {
            throw new AiContractValidationException('Empty input received, expected valid JSON.');
        }

        // Check if wrapped in markdown code blocks
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $trimmed, $matches)) {
            $jsonString = trim($matches[1]);
        } else {
            $jsonString = $trimmed;
        }

        try {
            $decoded = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AiContractValidationException(
                'Malformed JSON received from AI agent: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }

        if (!is_array($decoded)) {
            throw new AiContractValidationException(sprintf(
                'Expected JSON object or array, got %s.',
                gettype($decoded)
            ));
        }

        return $decoded;
    }
}
