<?php

namespace App\Http\Requests;

use App\Enums\CustomerStatus;
use App\Enums\ThriftPlanStatus;
use App\Enums\UserType;
use App\Models\BusinessProfile;
use App\Services\BusinessSettings;
use App\Services\MetricDefinitionService;
use App\Services\ReportCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $report = $this->route('report');
        $definition = app(ReportCatalogue::class)->get($this->user(), $report);
        $rules = [
            'page_size' => ['nullable', 'integer', Rule::in([25, 50, 100])],
            'cursor' => ['nullable', 'string', 'max:4096'],
            'group' => ['nullable', Rule::in($definition['groups'])],
            'metric' => ['nullable', 'required_with:basis_watermark', Rule::in(array_keys(MetricDefinitionService::DRILL_DOWN_REPORTS))],
            'basis_watermark' => ['nullable', 'required_with:metric', 'integer', 'min:0'],
        ];
        if ($definition['activity']) {
            $rules += ['from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
                'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from']];
        }
        $available = [
            'customer' => ['nullable', 'string', 'max:32', 'regex:/\A[A-Z0-9-]+\z/'],
            'plan' => ['nullable', 'string', 'max:32', 'regex:/\A[A-Z0-9-]+\z/'],
            'customer_status' => ['nullable', Rule::enum(CustomerStatus::class)],
            'plan_status' => ['nullable', Rule::enum(ThriftPlanStatus::class)],
            'state' => ['nullable', Rule::in(['pending_review', 'approved', 'processing', 'outcome_unknown', 'payment_failed', 'rejected', 'cancelled', 'expired', 'posted'])],
            'agent' => ['nullable', 'string', 'max:32', 'regex:/\A[A-Z0-9-]+\z/'],
            'agent_basis' => ['nullable', Rule::in(match ($report) {
                'contributions', 'collection-performance' => ['current', 'recording'],
                'reconciliation' => ['custody'],
                'agent-performance' => ['current'],
                default => ['current'],
            })],
        ];
        foreach ($definition['filters'] as $field) {
            if (in_array($field, ['agent', 'agent_basis'], true) && $this->user()->user_type !== UserType::Admin) {
                continue;
            }
            $rules[$field] = $available[$field];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This filter is not available for this report and role.');
            }
            if ($this->filled('agent') !== $this->filled('agent_basis')) {
                $validator->errors()->add('agent', 'Choose both an Agent and an attribution basis.');
            }
            if ($validator->errors()->isNotEmpty() || ! $this->filled('from')) {
                return;
            }
            $timezone = BusinessProfile::current()->timezone;
            $from = CarbonImmutable::parse($this->string('from')->toString(), $timezone);
            $to = CarbonImmutable::parse($this->string('to')->toString(), $timezone);
            if ($from->diffInDays($to) > 365 || $to->toDateString() > CarbonImmutable::now($timezone)->toDateString()) {
                $validator->errors()->add('to', 'Choose at most 366 inclusive dates ending no later than today.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function filters(string $timezone): array
    {
        $filters = array_filter(array_diff_key($this->validated(), ['metric' => true, 'basis_watermark' => true]), fn (mixed $value): bool => $value !== null && $value !== '');
        $today = CarbonImmutable::now($timezone);
        $defaults = app(BusinessSettings::class)->resolve()['values'];
        $filters['page_size'] ??= $defaults['page_size'];
        $filters['group'] ??= '';
        if (app(ReportCatalogue::class)->get($this->user(), $this->route('report'))['activity']) {
            $filters['from'] ??= match ($defaults['report_range']) {
                'today' => $today->toDateString(),
                'week' => $today->startOfWeek($defaults['week_start'] === 'Sunday' ? 0 : 1)->toDateString(),
                default => $today->startOfMonth()->toDateString(),
            };
            $filters['to'] ??= $today->toDateString();
        }
        ksort($filters);

        return $filters;
    }

    /** @return array{metric: string, basis_watermark: int}|null */
    public function drillDownBasis(): ?array
    {
        if (! $this->filled('metric')) {
            return null;
        }

        return ['metric' => $this->string('metric')->toString(), 'basis_watermark' => $this->integer('basis_watermark')];
    }
}
