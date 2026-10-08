<?php

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an end date against its start date: in order, at most the allowed number of inclusive days,
 * and optionally no later than today in the business timezone.
 */
class InclusiveDateRange implements ValidationRule
{
    public const MAX_DAYS = 366;

    /**
     * Create a new rule instance.
     */
    public function __construct(
        protected mixed $from,
        protected string $timezone,
        protected bool $endsByToday = true,
        protected int $maxDays = self::MAX_DAYS,
    ) {}

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $start = $this->date($this->from);
        $end = $this->date($value);
        if ($start === null || $end === null) {
            return;
        }

        if ($start->greaterThan($end)) {
            $fail('The end date must be on or after the start date.');

            return;
        }

        if ($start->diffInDays($end) + 1 > $this->maxDays) {
            $fail("Choose a date range of at most {$this->maxDays} days.");

            return;
        }

        if ($this->endsByToday && $end->toDateString() > CarbonImmutable::now($this->timezone)->toDateString()) {
            $fail('The end date cannot be later than today.');
        }
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! CarbonImmutable::canBeCreatedFromFormat($value, '!Y-m-d')) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);

        return $date !== null && $date->format('Y-m-d') === $value ? $date : null;
    }
}
