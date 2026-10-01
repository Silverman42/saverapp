<?php

namespace App\Http\Requests;

class StoreReplacementReceiptRequest extends StoreCollectionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'replacement_fingerprint' => ['required', 'string', 'size:64']];
    }
}
