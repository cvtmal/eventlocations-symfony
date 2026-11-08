<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * The calendar day a paid term is measured in (G9): Europe/Zurich, so a term that ends on 7 November covers that
 * whole Swiss day and gives way to Free at local midnight, whatever the server's zone.
 */
final readonly class TermDay
{
    private const string ZONE = 'Europe/Zurich';

    private function __construct()
    {
    }

    /**
     * @return \DateTimeImmutable the day of `$now` in Zurich, at midnight, in the default zone (a DATE value)
     */
    public static function of(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return new \DateTimeImmutable($now->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d'));
    }

    /**
     * `Y-m-d` of the Zurich day, for a DATE parameter.
     */
    public static function sql(\DateTimeImmutable $now): string
    {
        return self::of($now)->format('Y-m-d');
    }

    /**
     * The last day of a term that starts on `$startsOn` and runs one year: 8 November 2026 to 7 November 2027.
     */
    public static function oneYearFrom(\DateTimeImmutable $startsOn): \DateTimeImmutable
    {
        return $startsOn->setTime(0, 0)->modify('+1 year')->modify('-1 day');
    }
}
