<?php

namespace App\Support;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Validation\Rules\Password;

class PasswordPolicy
{
    /**
     * Get the default password validation rule.
     * Defaults to 15 characters (single-factor safe rule).
     */
    public static function defaultRule(): Password
    {
        $rule = Password::min(15);

        if (app()->isProduction()) {
            $rule->uncompromised();
        }

        return $rule;
    }

    /**
     * Get the password validation rule for a specific user type.
     */
    public static function ruleForUserType(UserType $userType): Password
    {
        $min = in_array($userType, [UserType::Agent, UserType::Admin], true) ? 8 : 15;

        $rule = Password::min($min);

        if (app()->isProduction()) {
            $rule->uncompromised();
        }

        return $rule;
    }

    /**
     * Get the password validation rule for a specific user.
     */
    public static function ruleForUser(?User $user = null): Password
    {
        if ($user === null) {
            return static::defaultRule();
        }

        return static::ruleForUserType($user->user_type);
    }
}
