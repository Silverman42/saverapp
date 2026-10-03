<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCashRemittanceRequest extends FormRequest
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
            'handoff_reference' => ['required', 'string', 'min:1', 'max:100', 'regex:/\A[A-Za-z0-9._:\/-]+\z/'],
            'amount_ngn' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/'],
            'handoff_date' => ['required', 'date_format:Y-m-d'],
            'receiving_location' => ['required', 'string', 'min:1', 'max:150'],
            'source_attestation' => ['required', 'string', 'min:1', 'max:500'],
            'batch_version' => ['required', 'integer', 'min:1'],
            'confirmed' => ['required', 'accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules()), ['_token', '_method']) as $field) {
                $validator->errors()->add($field, 'Only confirmed cash handoff evidence is supported; custody offsets are unavailable.');
            }
        });
    }
}
