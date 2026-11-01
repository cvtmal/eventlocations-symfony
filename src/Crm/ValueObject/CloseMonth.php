<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * A month of expected closes, `2026-11` in the URL (`abschluss=`), so a filtered board is a bookmark (D12).
 */
final readonly class CloseMonth
{
    public const string SHAPE = '/\A(?:19|20)\d{2}-(?:0[1-9]|1[0-2])\z/';

    private const string ZONE = 'Europe/Zurich';

    private function __construct()
    {
    }

    /**
     * The month's name: `November 2026`.
     */
    public static function label(string $month): string
    {
        $zone = new \DateTimeZone(self::ZONE);
        $label = new \IntlDateFormatter('de_CH', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $zone, null, 'LLLL yyyy')
            ->format(new \DateTimeImmutable($month.'-01', $zone));
        if (false === $label) {
            throw new \UnexpectedValueException(intl_get_error_message());
        }

        return $label;
    }
}
