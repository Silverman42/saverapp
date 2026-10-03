<?php

namespace App\Http\Requests;

use App\Enums\AdminPermission;
use App\Enums\FeeObligationStatus;
use App\Enums\FeeRuleKind;
use App\Enums\FeeRuleModel;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeeRegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && app(AuthorizationService::class)->allows($this->user(), AdminPermission::FeesManage);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when(filled($this->input('date_from')), 'after_or_equal:date_from')],
            'customer' => ['nullable', 'string', 'max:50'],
            'current_agent' => ['nullable', 'string', 'max:50'],
            'original_agent' => ['nullable', 'string', 'max:50'],
            'cycle' => ['nullable', 'string', 'max:50'],
            'kind' => ['nullable', Rule::enum(FeeRuleKind::class)],
            'model' => ['nullable', Rule::enum(FeeRuleModel::class)],
            'status' => ['nullable', Rule::in([...array_column(FeeObligationStatus::cases(), 'value'), 'unavailable'])],
            'source' => ['nullable', Rule::in(['registration', 'plan', 'plan_terms_revision', 'manual_charge'])],
            'currency' => ['nullable', Rule::in(['NGN'])],
            'refund_status' => ['nullable', Rule::in(['none', 'savings_returned', 'external_entitlement'])],
            'reconciliation_status' => ['nullable', Rule::in(['none', 'open', 'ready_for_review', 'in_review', 'exception', 'reconciled'])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /** @return array<string, string|int> */
    public function filters(): array
    {
        $values = $this->validated();
        $filters = [];
        foreach (['date_from', 'date_to', 'customer', 'current_agent', 'original_agent', 'cycle', 'kind', 'model',
            'status', 'source', 'currency', 'refund_status', 'reconciliation_status'] as $key) {
            $filters[$key] = (string) ($values[$key] ?? '');
        }
        $filters['sort'] = $values['sort'] ?? 'newest';
        $filters['per_page'] = (int) ($values['per_page'] ?? 25);

        return $filters;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['date_to.after_or_equal' => 'Choose an end date on or after the start date.'];
    }
}
