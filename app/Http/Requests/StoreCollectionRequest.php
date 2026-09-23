<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class StoreCollectionRequest extends PreviewCollectionRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'attempt_reference' => ['required', 'uuid'],
            'preview_fingerprint' => ['required', 'string', 'size:64'],
            'customer_version' => ['required', 'integer', 'min:1'],
            'assignment_version' => ['required', 'integer', 'min:1'],
            'business_version' => ['required', 'integer', 'min:1'],
            'plan_version' => ['nullable', 'integer', 'min:1'],
            'confirmed' => ['required', 'accepted'],
        ]);
    }
}
