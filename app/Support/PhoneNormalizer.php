<?php

namespace App\Support;

class PhoneNormalizer
{
    /**
     * Normalize a phone number to international E.164-compatible format.
     * Default country is Nigeria (+234).
     */
    public static function normalize(?string $phone, string $country = 'NG'): ?string
    {
        if ($phone === null) {
            return null;
        }

        $trimmed = trim($phone);
        if ($trimmed === '') {
            return null;
        }

        // Clean out spaces, dashes, dots, parentheses
        $cleaned = preg_replace('/[^\d+]/', '', $trimmed);
        if ($cleaned === null || $cleaned === '') {
            return null;
        }

        $country = strtoupper(trim($country));

        if ($country === 'NG') {
            return self::normalizeNigerianNumber($cleaned);
        }

        // Generic international validation
        if (! str_starts_with($cleaned, '+')) {
            $cleaned = '+'.$cleaned;
        }

        // E.164: + followed by 7 to 15 digits
        if (preg_match('/^\+[1-9]\d{6,14}$/', $cleaned)) {
            return $cleaned;
        }

        return null;
    }

    /**
     * Check whether a phone number is valid for the given country.
     */
    public static function isValid(?string $phone, string $country = 'NG'): bool
    {
        return self::normalize($phone, $country) !== null;
    }

    /**
     * Normalize a Nigerian phone number.
     */
    protected static function normalizeNigerianNumber(string $cleaned): ?string
    {
        // Handle +234 prefix
        if (str_starts_with($cleaned, '+234')) {
            $rest = substr($cleaned, 4);
            if (str_starts_with($rest, '0')) {
                $rest = substr($rest, 1);
            }
            if (strlen($rest) === 10 && ctype_digit($rest)) {
                return '+234'.$rest;
            }

            return null;
        }

        // Handle 234 prefix without +
        if (str_starts_with($cleaned, '234')) {
            $rest = substr($cleaned, 3);
            if (str_starts_with($rest, '0')) {
                $rest = substr($rest, 1);
            }
            if (strlen($rest) === 10 && ctype_digit($rest)) {
                return '+234'.$rest;
            }

            return null;
        }

        // Handle local 11-digit format starting with 0 (e.g. 08012345678)
        if (str_starts_with($cleaned, '0') && strlen($cleaned) === 11 && ctype_digit($cleaned)) {
            return '+234'.substr($cleaned, 1);
        }

        // Handle 10-digit number without leading 0 (e.g. 8012345678)
        if (strlen($cleaned) === 10 && ctype_digit($cleaned) && ! str_starts_with($cleaned, '0')) {
            return '+234'.$cleaned;
        }

        // If it starts with another international country code with +
        if (str_starts_with($cleaned, '+') && preg_match('/^\+[1-9]\d{6,14}$/', $cleaned)) {
            return $cleaned;
        }

        return null;
    }
}
