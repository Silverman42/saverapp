<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreThriftPlanRequest extends FormRequest
{
    /** @var array<int, string> */
    private const array PROTECTED_FIELDS = [
        'id', 'plan_id', 'customer_profile_id', 'created_by_user_id', 'status', 'open_customer_profile_id',
        'expected_gross_kobo', 'fee_snapshot_id', 'fee_amount_kobo', 'currency', 'frequency', 'amount_kobo',
        'revision', 'version', 'business_id', 'activity_started_at', 'active_ordinal', 'collection_status',
        'balance_kobo', 'amount_collected_kobo', 'completed_at', 'closed_at', 'timezone',
    ];

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
            'attempt_reference' => ['required', 'uuid'],
            'preview_fingerprint' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'amount_ngn' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'contribution_days' => ['required', 'integer', 'between:1,366'],
            'customer_visible_notes' => ['nullable', 'string', 'max:2000'],
            'fee_rule_id' => ['required', 'integer', 'min:1'],
            'fee_rule_version' => ['required', 'integer', 'min:1'],
            'customer_version' => ['required', 'integer', 'min:1'],
            'assignment_version' => ['required', 'integer', 'min:1'],
            'business_version' => ['required', 'integer', 'min:1'],
            'customer_agreement_attested' => ['required', 'accepted'],
            'predecessor_plan_id' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $input = $this->all();
            foreach (self::PROTECTED_FIELDS as $field) {
                if (array_key_exists($field, $input)) {
                    $validator->errors()->add($field, "Field [{$field}] is server-managed and cannot be supplied.");
                }
            }
        });
    }
}
