<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomerReassignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['reason', 'customer_explanation'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['attempt_reference' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'],
            'assignment_version' => ['required', 'integer', 'min:1'], 'target_agent_id' => ['required', 'integer', 'min:1'],
            'preview_token' => ['required', 'string', 'max:10000'], 'confirmed' => ['accepted'],
            'reason' => ['required', 'string', 'min:1', 'max:500'], 'customer_explanation' => ['required', 'string', 'min:1', 'max:500']];
    }
}
