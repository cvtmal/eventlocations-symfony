<?php

declare(strict_types=1);

namespace App\Cms\ValueObject;

/**
 * Swiss francs typed by staff, as integer Rappen (F12: `tiers.price_rappen`). The text is read as a string, never as
 * a float: `0.29` is 29 Rappen here, where `0.29 * 100` is 28.99… Up to two decimals, a comma counts as the decimal
 * point, and the Swiss grouping (`1'250`) is accepted only in its place, every three digits. A plain space is no
 * grouping: `199 50` is a typo and an error, not CHF 19'950. Crm has its own `Chf` for deals; Cms may not depend on it.
 */
final readonly class Francs
{
    // [0-9], not \d: under /u the pattern would also take other scripts' digits, which (int) reads as 0.
    private const string SHAPE = '/\A([0-9]{1,8}|[0-9]{1,3}(?:[\'’\x{00A0}\x{202F}][0-9]{3}){1,2})(?:[.,]([0-9]{1,2}))?\z/u';

    private function __construct()
    {
    }

    /**
     * @throws \InvalidArgumentException when the text is no amount of at most two decimals
     */
    public static function parse(string $text): int
    {
        if (1 !== preg_match(self::SHAPE, $text, $parts)) {
            throw new \InvalidArgumentException('Not an amount in francs.');
        }

        return (int) preg_replace('/[^0-9]/', '', $parts[1]) * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    /**
     * Whole francs when there are no Rappen, else two decimals: `199`, `199.50`. The text `parse()` reads back.
     */
    public static function format(int $rappen): string
    {
        return 0 === $rappen % 100 ? (string) intdiv($rappen, 100) : \sprintf('%d.%02d', intdiv($rappen, 100), $rappen % 100);
    }
}
