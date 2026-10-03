<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class RetireFeeRuleRequest extends PreviewFeeRuleRetirementRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return parent::rules() + [
            'confirmed' => ['required', 'accepted'],
            'preview_fingerprint' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }
}
