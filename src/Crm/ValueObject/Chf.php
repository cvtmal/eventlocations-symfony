<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * Swiss francs as integer Rappen (S13, D6). Everything goes through strings, never a float: `0.29` is 29 Rappen here,
 * where `0.29 * 100` is 28.99… The limit (N10) keeps every sum of a board safe in JS. `format()` writes what
 * `swiss_number` writes, `admin-ui/tests/fixtures/chf.json` holds both sides to it.
 */
final readonly class Chf
{
    /** CHF 99'999'999.99. */
    public const int MAX_RAPPEN = 9_999_999_999;

    private const string SHAPE = '/\A(\d{1,20})(?:\.(\d{1,2}))?\z/';
    private const array GROUPING = ["'", '’', ' ', "\u{00A0}", "\u{202F}"];

    private function __construct()
    {
    }

    /**
     * @throws \InvalidArgumentException when the text is no amount of at most two decimals up to the limit
     */
    public static function parse(string $text): int
    {
        if (1 !== preg_match(self::SHAPE, str_replace(self::GROUPING, '', $text), $parts)) {
            throw new \InvalidArgumentException('Not an amount in francs.');
        }

        $francs = ltrim($parts[1], '0');
        if (\strlen($francs) > 8) {
            throw new \InvalidArgumentException('The amount is above the limit.');
        }

        return (int) ($francs.str_pad($parts[2] ?? '', 2, '0'));
    }

    /**
     * Whole francs when there are no Rappen, else two decimals: `1'250`, `1'250.50`.
     */
    public static function format(int $rappen): string
    {
        $grouped = strrev(implode("'", str_split(strrev((string) intdiv($rappen, 100)), 3)));
        $cents = $rappen % 100;

        return 0 === $cents ? $grouped : \sprintf('%s.%02d', $grouped, $cents);
    }

    /**
     * With the currency, as a page shows it: `CHF 1'250.50`.
     */
    public static function display(int $rappen): string
    {
        return 'CHF '.self::format($rappen);
    }

    /**
     * As a number a spreadsheet reads, without grouping: `1250.50`.
     */
    public static function plain(int $rappen): string
    {
        return \sprintf('%d.%02d', intdiv($rappen, 100), $rappen % 100);
    }
}
