<?php

namespace App\Http\Requests;

use App\Enums\AdminPermission;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FeeActionAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && app(AuthorizationService::class)->allows($actor, AdminPermission::FeesManage);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $savings = $this->input('operation') === 'apply_savings';
        $fields = $savings ? ['plan_id', 'reason', 'customer_description', 'attempt_reference', 'confirmed', 'preview_fingerprint', 'quote_expires_at']
            : ['amount_ngn', 'reason', 'customer_description', 'attempt_reference', 'confirmed', ...($this->input('operation') === 'correct' ? ['direction'] : [])];
        $rules = ['operation' => ['required', 'in:waive,correct,apply_savings'], 'attempt_reference' => ['required', 'uuid'],
            'payload' => [$this->routeIs('admin.fees.obligations.attempts.cancel') ? 'sometimes' : 'required', 'array:'.implode(',', $fields), 'min:1'], 'payload.attempt_reference' => ['sometimes', 'uuid', 'same:attempt_reference'],
            'payload.reason' => ['required_with:payload', 'string', 'min:1', 'max:500'], 'payload.customer_description' => ['required_with:payload', 'string', 'min:1', 'max:500']];
        if ($savings) {
            return $rules + ['payload.plan_id' => ['required_with:payload', 'string', 'max:100'], 'payload.confirmed' => ['exclude_without:payload', 'required_with:payload', 'accepted'],
                'payload.preview_fingerprint' => ['required_with:payload', 'regex:/\A[a-f0-9]{64}\z/'], 'payload.quote_expires_at' => ['required_with:payload', 'date']];
        }

        return $rules + ['payload.amount_ngn' => ['required_with:payload', 'string', 'regex:/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?\z/'],
            ...($this->input('operation') === 'correct' ? ['payload.direction' => ['required_with:payload', 'in:reduce,increase']] : [])];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->except('_token')), ['operation', 'attempt_reference', 'payload']) !== []) {
                $validator->errors()->add('request', 'Unexpected fee action instructions.');
            }
        }];
    }
}
