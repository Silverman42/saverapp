<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordCashRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['preview_fingerprint' => ['required', 'string', 'size:64'], 'recovery_reference' => ['required', 'uuid'], 'evidence' => ['required', 'string', 'max:1000'],
            'amount_ngn' => ['nullable', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/'],
            'event_type' => ['sometimes', 'in:return,dispute,custody_uncertain'], 'confirmed' => ['required', 'accepted']];
    }
}
