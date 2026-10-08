<?php

namespace App\Support;

use Inertia\Inertia;

/**
 * Flashes the one-time toast shown after a non-GET action redirects back to a page.
 */
final class Toast
{
    public const TYPES = ['success', 'info', 'warning', 'error'];

    public static function success(string $title, ?string $description = null): void
    {
        self::show('success', $title, $description);
    }

    public static function info(string $title, ?string $description = null): void
    {
        self::show('info', $title, $description);
    }

    public static function warning(string $title, ?string $description = null): void
    {
        self::show('warning', $title, $description);
    }

    public static function error(string $title, ?string $description = null): void
    {
        self::show('error', $title, $description);
    }

    /**
     * @param  'success'|'info'|'warning'|'error'  $type
     */
    public static function show(string $type, string $title, ?string $description = null): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unsupported toast type [{$type}].");
        }

        Inertia::flash('toast', array_filter(
            ['type' => $type, 'title' => $title, 'description' => $description],
            fn (?string $value): bool => $value !== null && $value !== '',
        ));
    }
}
