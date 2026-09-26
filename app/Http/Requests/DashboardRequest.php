<?php

namespace App\Http\Requests;

use App\Enums\CustomerStatus;
use App\Enums\ThriftPlanStatus;
use App\Enums\UserType;
use App\Models\BusinessProfile;
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
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
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
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->input('period') === 'custom') {
                $timezone = BusinessProfile::current()->timezone;
                $from = CarbonImmutable::parse($this->string('from')->toString(), $timezone);
                $to = CarbonImmutable::parse($this->string('to')->toString(), $timezone);
                if ($from->diffInDays($to) > 365 || $to->toDateString() > CarbonImmutable::now($timezone)->toDateString()) {
                    $validator->errors()->add('to', 'Choose at most 366 inclusive dates ending no later than today.');
                }
            }
        });
    }

    /** @return array<string, mixed> */
    public function filters(string $timezone): array
    {
        $filters = $this->validated();
        $today = CarbonImmutable::now($timezone);
        $filters['period'] ??= 'today';
        if ($filters['period'] !== 'custom') {
            $filters['from'] = match ($filters['period']) {
                'week' => $today->startOfWeek()->toDateString(),
                'month' => $today->startOfMonth()->toDateString(),
                default => $today->toDateString(),
            };
            $filters['to'] = $today->toDateString();
        }
        $filters['page_size'] ??= 25;

        return $filters;
    }
}
