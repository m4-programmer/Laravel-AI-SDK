<?php

namespace App\Services\Coa;

use App\Ai\Agents\CoaJsonCompletionAgent;
use Stringable;
use Throwable;

/**
 * Text completion helper mirroring `get_gemini_model` + `generate_content` in Python.
 */
class CoaLlm
{
    public function __construct(
        private ?CoaJsonCompletionAgent $agent = null
    ) {
        $this->agent ??= new CoaJsonCompletionAgent;
    }

    /**
     * @return array|list<mixed>
     */
    public function completeJson(string $userPrompt): array
    {
        $text = $this->completeText($userPrompt);

        try {
            $parsed = CoaJsonParser::parse($text);
        } catch (Throwable $e) {
            throw $e;
        }

        return is_array($parsed) ? $parsed : [$parsed];
    }

    public function completeText(string $userPrompt): string
    {
        $response = $this->agent->prompt($userPrompt);
        if ($response instanceof Stringable) {
            return (string) $response;
        }

        return (string) $response;
    }
}
