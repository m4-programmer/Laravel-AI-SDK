<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Python: `COAChatRequest` in automative_assistant `src/api/schemas/requests.php`.
 */
class COAChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => 'required|string|min:1|max:8000',
            'active_class_tab' => 'required|string',
            'branch_label' => 'nullable|string|max:255',
            'organization_type' => 'sometimes|string',
            'region' => 'sometimes|string',
            'coa_context' => 'nullable|array',
            'load_hierarchy_from_db' => 'sometimes|boolean',
            'locked_entity_keys' => 'nullable|array',
            'locked_entity_keys.*' => 'string',
            'restrict_destructive_to_ai_generated' => 'sometimes|boolean',
            'conversation_history' => 'nullable|array',
            'conversation_history.*.role' => 'string',
            'conversation_history.*.content' => 'string',
            'conversation_history.*.turn_number' => 'nullable|integer',
            'conversation_id' => 'nullable|string|max:128',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('organization_type')) {
            $this->merge(['organization_type' => 'general']);
        }
        if (! $this->has('region')) {
            $this->merge(['region' => 'NG']);
        }
    }
}
