<?php

namespace App\Services\Coa;

use JsonException;

/**
 * Port of `automative_assistant/src/utils/json_parser.py` ::parse_json_response.
 *
 * @return array<mixed> (list or map — same as json_decode(..., true))
 */
class CoaJsonParser
{
    public static function parse(string $response): array
    {
        $cleaned = trim($response);

        if (str_starts_with($cleaned, '```json')) {
            $cleaned = substr($cleaned, 7);
        } elseif (str_starts_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 3);
        }

        if (str_ends_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 0, -3);
        }

        $cleaned = trim($cleaned);

        try {
            $decoded = json_decode($cleaned, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new JsonException('COA LLM returned invalid JSON: '.$e->getMessage(), 0, $e);
        }

        if (is_array($decoded)) {
            return $decoded;
        }

        return [$decoded];
    }
}
