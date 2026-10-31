<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * The date of a timeline entry as the composer's `datetime-local` field holds it: `2026-10-31T14:05`, local time
 * in Zurich. Anything else is no date. That covers the wall-clock hour a clock change skips, and a year outside
 * 1900–2099: Postgres refuses year 0, and before 1894 Zurich's offset has seconds, which `O` cannot write.
 */
final readonly class ActivityDate
{
    private const string FORMAT = 'Y-m-d\TH:i';
    private const string SHAPE = '/\A(?:19|20)\d{2}-\d{2}-\d{2}T\d{2}:\d{2}\z/';
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

    public static function local(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone(self::ZONE))->format(self::FORMAT);
    }
}
