<?php

namespace App\Http\Requests;

use App\Enums\AdminPermission;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class ReviewCollectionEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app(AuthorizationService::class)->allows($this->user(), AdminPermission::ReconciliationManage);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operation_reference' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'outcome' => ['required', 'in:verified,rejected'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'verified_reference' => ['required_if:outcome,verified', 'nullable', 'string', 'max:120'],
            'verified_amount_ngn' => ['required_if:outcome,verified', 'nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'verified_destination_key' => ['required_if:outcome,verified', 'nullable', 'string', 'max:100'],
        ];
    }
}
