<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PlanTransitionRequest extends FormRequest
{
    /** @var array<int, string> */
    private const array PROTECTED_FIELDS = [
        'id', 'plan_id', 'customer_profile_id', 'created_by_user_id', 'status', 'open_customer_profile_id',
        'activity_started_at', 'balance_kobo', 'amount_collected_kobo', 'completed_at', 'closed_at', 'fee_snapshot_id',
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
            'plan_version' => ['required', 'integer', 'min:1'],
            'customer_version' => ['required', 'integer', 'min:1'],
            'assignment_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'customer_explanation' => ['required', 'string', 'min:3', 'max:500'],
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
