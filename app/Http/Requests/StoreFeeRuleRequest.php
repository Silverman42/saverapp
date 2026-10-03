<?php

namespace App\Http\Requests;

use App\Enums\AdminPermission;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFeeRuleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, AdminPermission::FeesManage);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['sometimes', 'string', 'in:registration,plan'],
            'rule_key' => ['required_if:kind,plan', 'nullable', 'string', 'max:100', 'regex:/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/'],
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'model' => ['required', 'string', 'in:fixed,no_fee,one_day,percentage'],
            'timing' => ['required_if:kind,plan', 'nullable', 'string', 'in:registration,first_contribution,cycle_completion,withdrawal'],
            'basis' => ['nullable', 'string', 'in:none,contractual_daily_contribution,net_cycle_contributions,gross_withdrawal_debit'],
            'settlement_source' => ['nullable', 'string', 'in:external_receipt,savings_application,withdrawal_payout'],
            'amount_ngn' => ['nullable', 'numeric', 'regex:/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?\z/'],
            'currency' => ['sometimes', 'string', 'in:NGN'],
            'basis_points' => ['required_if:model,percentage', 'nullable', 'integer', 'min:0', 'max:10000'],
            'customer_description' => ['required', 'string', 'min:1', 'max:500'],
            'publication_reason' => ['required', 'string', 'min:1', 'max:500'],
            'effective_at' => ['nullable', 'date'],
            'confirmed' => ['required', 'accepted'],
            'preview_fingerprint' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }
}
