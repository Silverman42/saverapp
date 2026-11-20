<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReplacementWalletCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['attempt_reference' => ['required', 'uuid'], 'preview_fingerprint' => ['required', 'string', 'size:64'], 'confirmed' => ['required', 'accepted']];
    }
}
