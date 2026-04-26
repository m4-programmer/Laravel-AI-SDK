<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Structured output agent for COA review chat.
 * @see \App\Services\Coa\CoaChatService
 *
 * Text provider defaults from config/ai.php — set default to Gemini in .env
 * to mirror the Python get_gemini_model() path, or pass provider/model on ->prompt().
 *  
 * This schema matches the "Return VALID JSON ONLY" block in
 * `coa_chat_service.py` (raw model output: `proposed_operations`).
 * The **HTTP** `COAChatResponse` in `schemas/responses.py` is a different layer: the
 * service adds `success`, `message_id`, `rejected_due_to_*`, and rewrites
 * `proposed_operations` → `proposed_changes` with `op_id`, `before`, `after`, `target`, `placement`.
 *
 * @link https://laravel.com/docs/13.x/ai-sdk
 * @link https://laravel.com/ai
 */

#[Provider(Lab::Gemini)]
#[Model('gemini-2.5-flash')]
class CoaReviewAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * System instructions: role + output JSON contract (aligned with coa_chat_service.py).
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
            You are a professional accounting assistant embedded in a "Review chart of accounts" screen.

            The user message you receive will include CONVERSATION_HISTORY, COA_SUBTREE JSON, LOCKED_ENTITIES, flags, and USER_MESSAGE.

            You MUST return JSON matching the schema. Rules:
            1. Use COA_SUBTREE and CONVERSATION_HISTORY for all answers. Do not invent data.
            2. assistant_message: 1–4 plain sentences. State what you are doing or what you found. No rhetorical questions asking permission to proceed.
            3. proposed_operations: EMPTY array for pure questions or explanations — do not use answer_only rows.
            4. For rename_account: include concrete non-null new_name in Title Case. If the user is vague, infer a professional name from context.
            5. add_account: include name and parent_code (or parent ids).
            6. remove_account, merge_accounts, move_account: include entity_id and/or entity_code.
            7. update_description: include entity_id/entity_code and new_description.
            8. toggle_active: include entity_id/entity_code.
            9. update_code: include entity_id/entity_code and new_code.
            10. If multiple accounts need changes, emit one op object per account.
            11. Never say changes have been saved.
            INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'assistant_message' => $schema->string()->required(),
            'suggested_follow_up_questions' => $schema->array()
                ->items($schema->string())
                ->required(),
            'proposed_operations' => $schema->array()
                ->items(
                    $schema->object(
                        fn (JsonSchema $inner) => [
                            'operation' => $inner->string()->required(),
                            'entity_id' => $inner->number(),
                            'entity_code' => $inner->string(),
                            'entity_level' => $inner->string(),
                            'name' => $inner->string(),
                            'new_name' => $inner->string(),
                            'new_code' => $inner->string(),
                            'new_description' => $inner->string(),
                            'parent_code' => $inner->string(),
                            'parent_type_id' => $inner->number(),
                            'parent_detail_id' => $inner->number(),
                            'merge_into_entity_id' => $inner->number(),
                            'merge_into_entity_code' => $inner->string(),
                            'rationale' => $inner->string(),
                        ]
                    )
                )
                ->required(),
        ];
    }
}
