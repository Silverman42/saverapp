<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PreviewCollectionRequest extends FormRequest
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
            'currency' => ['sometimes', 'in:NGN'],
            'method' => ['sometimes', 'in:cash'],
            'plan_id' => ['nullable', 'string', 'max:32'],
            'received_date' => ['required', 'date_format:Y-m-d'],
            'received_local_time' => ['nullable', 'date_format:H:i'],
            'received_utc_offset' => ['nullable', 'regex:/\A[+-](?:0[0-9]|1[0-4]):[0-5][0-9]\z/'],
            'savings_ngn' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/'],
            'fees' => ['sometimes', 'array', 'max:20'],
            'fees.*.obligation_id' => ['required', 'integer', 'min:1'],
            'fees.*.amount_ngn' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/'],
            'allocations' => ['sometimes', 'array', 'max:366'],
            'allocations.*.slot_id' => ['required', 'integer', 'min:1'],
            'allocations.*.amount_ngn' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/'],
            'late_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
