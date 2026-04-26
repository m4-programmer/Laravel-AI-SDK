<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class COAChildrenSingleRequest extends FormRequest
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
            'organization_type' => 'required|string|min:1',
            'region' => 'sometimes|string',
            'suggestions_count' => 'sometimes|integer|min:1|max:10',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('region')) {
            $this->merge(['region' => 'NG']);
        }
        if (! $this->has('suggestions_count')) {
            $this->merge(['suggestions_count' => 3]);
        }
    }
}
