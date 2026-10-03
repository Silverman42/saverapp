<?php

namespace App\Http\Requests;

use App\Enums\AdminPermission;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class StoreCollectionSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app(AuthorizationService::class)->allows($this->user(), AdminPermission::ReconciliationManage);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'settlement_reference' => ['required', 'uuid'],
            'batch_version' => ['required', 'integer', 'min:1'],
            'bank_method_version_id' => ['required', 'integer', 'min:1'],
            'bank_reference' => ['required', 'string', 'max:120', 'regex:/\A[A-Za-z0-9._:\/-]+\z/'],
            'settled_date' => ['required', 'date_format:Y-m-d'],
            'amount_ngn' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/'],
            'source_attestation' => ['required', 'string', 'min:10', 'max:1000'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'files' => ['required', 'array', 'min:1', 'max:3'],
            'files.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'confirmed' => ['required', 'accepted'],
        ];
    }
}
