<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class SecurityCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app(AuthorizationService::class)->allows($this->user()->fresh(), AdminPermission::SecurityOperationsManage);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['expected_version' => ['required', 'integer', 'min:1'], 'action' => ['required', 'in:assign,state,note,reopen'],
            'owner_id' => ['nullable', 'integer', 'min:1'], 'state' => ['nullable', 'required_if:action,state', 'in:Investigating,Resolved,ClosedNoAction'],
            'note' => ['required_if:action,note,reopen,state', 'nullable', 'string', 'min:5', 'max:2000'],
            'evidence_references' => ['sometimes', 'array', 'max:10'], 'evidence_references.*' => ['string', 'distinct', 'regex:/\A(?:audit:[0-9A-HJKMNP-TV-Z]{26}|lock:[0-9]+)\z/']];
    }
}
