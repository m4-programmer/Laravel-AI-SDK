<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * Unstructured text/JSON from the model — used by COA children, suggestions, and linked COA
 * (Python: `get_gemini_model` + `generate_content`).
 */
#[MaxTokens(8192)]
#[Timeout(180)]
class CoaJsonCompletionAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You follow the user message exactly. When the user requests JSON, output only valid JSON. '
            .'Do not wrap the JSON in markdown code fences unless explicitly asked.';
    }
}
