<?php

namespace App\Support;

class IdentityNormalizer
{
    /**
     * Authoritatively normalize an email address according to Section 4.2:
     * Trim surrounding whitespace and convert to lowercase.
     * Do not remove dots, remove plus-address suffixes, or apply provider-specific alias rules.
     */
    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $trimmed = trim($email);

        return mb_strtolower($trimmed, 'UTF-8');
    }

    /**
     * Validate an email address format.
     */
    public static function isValidEmail(string $email): bool
    {
        $trimmed = trim($email);

        return filter_var($trimmed, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Normalize a phone number to international format.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        return PhoneNormalizer::normalize($phone);
    }
}
