<?php

declare(strict_types=1);

namespace App\Crm\Form;

/**
 * Where a task toggle goes back to (P11): a hidden `return_to`, which is client input, so only a back-office path
 * passes. Anything else, a scheme, `//host`, a backslash, a line break or invalid UTF-8, gives the fallback.
 */
final readonly class ReturnPath
{
    private function __construct()
    {
    }

    public static function safe(mixed $value, string $fallback): string
    {
        if (!\is_string($value) || !str_starts_with($value, '/admin/') || str_contains($value, '//')) {
            return $fallback;
        }

        return mb_check_encoding($value, 'UTF-8') && 1 !== preg_match('/[\x00-\x1f\x7f\\\\]/', $value) ? $value : $fallback;
    }
}
