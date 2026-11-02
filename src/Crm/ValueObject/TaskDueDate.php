<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * A task's due date as the date fields hold it: `2026-11-03`. Anything else is no date, the 31st of a short month
 * and a year outside 1900–2099 (`ActivityDate`'s range) included. "Today" is the Zurich calendar day of a moment.
 */
final readonly class TaskDueDate
{
    public const string SHAPE = '/\A(?:19|20)\d{2}-\d{2}-\d{2}\z/';

    private const string FORMAT = 'Y-m-d';
    private const string ZONE = 'Europe/Zurich';

    private function __construct()
    {
    }

    public static function parse(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match(self::SHAPE, $value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!'.self::FORMAT, $value, new \DateTimeZone(self::ZONE));

        return false !== $date && $date->format(self::FORMAT) === $value ? $date : null;
    }

    public static function today(\DateTimeImmutable $now): string
    {
        return $now->setTimezone(new \DateTimeZone(self::ZONE))->format(self::FORMAT);
    }

    /**
     * Counted on Zurich's calendar, so an accept after midnight there is due from that day.
     */
    public static function daysAfter(\DateTimeImmutable $now, int $days): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::today($now))->modify(\sprintf('+%d days', $days));
    }
}
