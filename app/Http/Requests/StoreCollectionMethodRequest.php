<?php

namespace App\Http\Requests;

use App\Enums\AdminPermission;
use App\Services\AuthorizationService;
use App\Services\CollectionMethodCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCollectionMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app(AuthorizationService::class)->allows($this->user(), AdminPermission::BusinessSettingsManage);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'publication_reference' => ['required', 'uuid'],
            'method_key' => ['required', 'in:transfer,pos,other'],
            'version' => ['required', 'integer', 'min:1'],
            'label' => ['required', 'string', 'max:100'],
            'custody_account_code' => ['required', 'in:agent_receivable_ngn,business_bank_ngn,payment_clearing_ngn'],
            'mapping_version' => ['required', 'integer', 'min:1'],
            'destination_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
            'attachment_required' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), CollectionMethodCatalogue::PUBLICATION_FIELDS, ['_token', '_method']) as $field) {
                $validator->errors()->add($field, 'This field is not supported for method publication.');
            }
        });
    }
}
