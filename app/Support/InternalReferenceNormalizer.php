<?php

namespace App\Support;

class InternalReferenceNormalizer
{
    /**
     * Normalize an internal reference for case-insensitive comparison.
     */
    public static function normalize(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        $trimmed = trim($reference);
        if ($trimmed === '') {
            return null;
        }

        return mb_strtolower($trimmed, 'UTF-8');
    }

    /**
     * Determine if an internal reference meets formatting rules (1-50 alphanumeric, hyphen, underscore, slash).
     */
    public static function isValid(?string $reference): bool
    {
        if ($reference === null) {
            return true;
        }

        $trimmed = trim($reference);
        if ($trimmed === '') {
            return true;
        }

        return preg_match('/^[A-Za-z0-9_\-\/]{1,50}$/', $trimmed) === 1;
    }
}
