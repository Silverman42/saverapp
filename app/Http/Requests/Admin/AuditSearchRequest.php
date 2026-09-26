<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class AuditSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app(AuthorizationService::class)->allows($this->user()->fresh(), AdminPermission::AuditView);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'], 'cursor' => ['nullable', 'string', 'max:4000'],
            'event_id' => ['nullable', 'ulid'], 'event_type' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:40'], 'outcome' => ['nullable', 'in:Succeeded,Denied,Failed,Conflict,Expired'],
            'severity' => ['nullable', 'in:Informational,Low,Medium,High,Critical'], 'actor_id' => ['nullable', 'integer', 'min:1'],
            'actor_type' => ['nullable', 'string', 'max:50'], 'target_type' => ['nullable', 'string', 'max:100'],
            'target_reference' => ['nullable', 'string', 'max:100'], 'source_module' => ['nullable', 'string', 'max:40'],
            'required_permission' => ['nullable', 'string', 'max:100'], 'correlation_reference' => ['nullable', 'string', 'max:100'],
            'retention_class' => ['nullable', 'in:business_evidence,security_evidence,protected_access']];
    }
}
