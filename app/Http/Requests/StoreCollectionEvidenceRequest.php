<?php

namespace App\Http\Requests;

use App\Models\CustomerProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\File;

class StoreCollectionEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $this->user() !== null && $customer instanceof CustomerProfile && Gate::allows('recordCollection', $customer);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'evidence_reference' => ['required', 'uuid'],
            'customer_version' => ['required', 'integer', 'min:1'],
            'assignment_version' => ['required', 'integer', 'min:1'],
            'collection_method_version_id' => ['required', 'integer', 'min:1'],
            'method_reference' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9 ._:\/-]+$/'],
            'received_date' => ['required', 'date_format:Y-m-d'],
            'amount_ngn' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'source_attestation' => ['required', 'string', 'min:10', 'max:2000'],
            'files' => ['sometimes', 'array', 'max:3'],
            'files.*' => ['required', File::types(['jpg', 'jpeg', 'png', 'webp', 'pdf'])->max(5120)],
        ];
    }
}
