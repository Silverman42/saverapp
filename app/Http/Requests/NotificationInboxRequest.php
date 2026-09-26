<?php

namespace App\Http\Requests;

use App\Models\BusinessProfile;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class NotificationInboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && (bool) config('notifications.enabled');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'read' => ['nullable', Rule::in(['all', 'unread'])],
            'category' => ['nullable', Rule::in(['account', 'financial', 'plan'])],
            'status' => ['nullable', Rule::in(['current', 'expired', 'superseded'])],
            'action_required' => ['nullable', 'boolean'], 'search' => ['nullable', 'string', 'max:160'],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page_size' => ['nullable', 'integer', Rule::in([25, 50, 100])],
            'cursor' => ['nullable', 'string', 'max:8192'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This inbox filter is unavailable.');
            }
            if ($validator->errors()->isNotEmpty() || ! $this->filled('from')) {
                return;
            }
            $timezone = BusinessProfile::current()->timezone;
            $from = CarbonImmutable::parse($this->string('from')->toString(), $timezone);
            $to = CarbonImmutable::parse($this->string('to')->toString(), $timezone);
            if ($from->diffInDays($to) + 1 > 366 || $to->isAfter(CarbonImmutable::today($timezone))) {
                $validator->errors()->add('to', 'Choose up to 366 dates ending no later than today.');
            }
        });
    }
}
