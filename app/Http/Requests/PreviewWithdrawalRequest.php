<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PreviewWithdrawalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'string', 'max:32'],
            'type' => ['required', 'string', 'in:partial,full,end_of_cycle'],
            'gross_ngn' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/'],
            'method' => ['required', 'string', 'in:cash,bank_transfer'],
            'destination_reference' => ['required', 'string', 'max:200'],
            'reason' => ['required', 'string', 'min:1', 'max:500', 'not_regex:/[<>\x00-\x08\x0B\x0C\x0E-\x1F]/'],
            'internal_notes' => ['nullable', 'string', 'max:1000', 'not_regex:/[<>\x00-\x08\x0B\x0C\x0E-\x1F]/'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->except('_token')), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Unknown withdrawal field.');
            }
        }];
    }
}
