<?php

declare(strict_types=1);

namespace App\Crm\Form;

use Symfony\Component\HttpFoundation\Request;

/**
 * `sort`, `dir` and `page` of a CRM list, read beside its filter form. An unknown sort or direction
 * falls back to the default, a page outside the range is clamped by the query (#24's rule).
 */
final readonly class ListQueryString
{
    private function __construct()
    {
    }

    public static function value(Request $request, string $name): string
    {
        $value = $request->query->all()[$name] ?? null;

        return \is_string($value) ? $value : '';
    }

    public static function page(Request $request): int
    {
        $page = filter_var(self::value($request, 'page'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return false === $page ? 1 : $page;
    }
}
