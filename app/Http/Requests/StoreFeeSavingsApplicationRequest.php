<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class StoreFeeSavingsApplicationRequest extends PreviewFeeSavingsApplicationRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return parent::rules() + ['attempt_reference' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted'],
            'preview_fingerprint' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'quote_expires_at' => ['required', 'date']];
    }
}
