<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class PreviewFeeRulePublicationRequest extends StoreFeeRuleRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['confirmed'], $rules['preview_fingerprint']);

        return $rules;
    }
}
