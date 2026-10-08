<?php

namespace App\Http\Requests;

use App\Enums\CustomerStatus;
use App\Enums\ThriftPlanStatus;
use App\Enums\UserType;
use App\Models\BusinessProfile;
use App\Rules\InclusiveDateRange;
use App\Services\BusinessSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DashboardRequest extends FormRequest
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
            'period' => ['nullable', Rule::in(['today', 'week', 'month', 'custom'])],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', new InclusiveDateRange($this->input('from'), BusinessProfile::current()->timezone)],
            'customer_status' => ['nullable', Rule::enum(CustomerStatus::class)],
            'plan_status' => ['nullable', Rule::enum(ThriftPlanStatus::class)],
            'agent_basis' => ['nullable', Rule::in(['current', 'recording'])],
            'agent' => ['nullable', 'string', 'max:32', 'regex:/\A[A-Z0-9-]+\z/'],
            'page_size' => ['nullable', 'integer', Rule::in([25, 50, 100])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->except('_token')), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Unknown dashboard filter.');
            }
            if ($this->user()?->user_type !== UserType::Admin && ($this->filled('agent') || $this->filled('agent_basis'))) {
                $validator->errors()->add('agent', 'Agent filters are available only on the Admin dashboard.');
            }
            if ($this->filled('agent') !== $this->filled('agent_basis')) {
                $validator->errors()->add('agent', 'Choose both an Agent and an attribution basis.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function filters(string $timezone): array
    {
        $filters = $this->validated();
        $today = CarbonImmutable::now($timezone);
        $configuration = app(BusinessSettings::class)->resolve();
        $defaults = $configuration['values'];
        $filters['period'] ??= $defaults['dashboard_activity_range'];
        if ($filters['period'] !== 'custom') {
            $filters['from'] = match ($filters['period']) {
                'week' => $today->startOfWeek($defaults['week_start'] === 'Sunday' ? 0 : 1)->toDateString(),
                'month' => $today->startOfMonth()->toDateString(),
                default => $today->toDateString(),
            };
            $filters['to'] = $today->toDateString();
        }
        $filters['page_size'] ??= $defaults['page_size'];

        return $filters;
    }
}
