<?php

namespace App\Rules;

use App\Models\User;
use App\Support\IdentityNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueNormalizedEmail implements ValidationRule
{
    /**
     * Create a new rule instance.
     */
    public function __construct(
        protected ?int $ignoreId = null,
        protected string $column = 'email_normalized'
    ) {}

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $normalized = IdentityNormalizer::normalizeEmail($value);

        if ($normalized === null || $normalized === '') {
            return;
        }

        $query = User::where($this->column, $normalized);

        if ($this->ignoreId !== null) {
            $query->where('id', '!=', $this->ignoreId);
        }

        if ($query->exists()) {
            $fail(__('The :attribute has already been taken.'));
        }
    }
}
