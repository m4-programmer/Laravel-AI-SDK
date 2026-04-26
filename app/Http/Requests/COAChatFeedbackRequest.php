<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Python: `COAChatFeedbackRequest`.
 */
class COAChatFeedbackRequest extends FormRequest
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
            'conversation_id' => 'nullable|string',
            'message_id' => 'nullable|string',
            'rating' => 'required|in:up,down',
            'comment' => 'nullable|string|max:2000',
            'tenant_id' => 'nullable|string|max:128',
        ];
    }
}
