<?php

declare(strict_types=1);

namespace App\Portal\ValueObject;

/**
 * The calendar month a monthly report (G10) covers, as its first day at midnight in Europe/Zurich. The label comes
 * from intl (`de_CH`, `LLLL y`: «Oktober 2026»), never from a hand-made list of month names.
 */
final readonly class ReportMonth
{
    /** The nightly step sends last month's report from the 1st up to this day of the month (Zurich), then waits. */
    public const int CATCH_UP_LAST_DAY = 7;

    private const string ZONE = 'Europe/Zurich';

    private function __construct(
        private \DateTimeImmutable $firstDay,
    ) {
    }

    /**
     * The calendar month before the one `$now` falls in, in Zurich.
     */
    public static function before(\DateTimeImmutable $now): self
    {
        $local = $now->setTimezone(new \DateTimeZone(self::ZONE));

        return self::of((int) $local->format('Y'), (int) $local->format('n'))->previous();
    }

    /**
     * `YYYY-MM`, else null.
     */
    public static function fromString(string $yyyyMm): ?self
    {
        if (1 !== preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $yyyyMm, $match)) {
            return null;
        }

        return self::of((int) $match[1], (int) $match[2]);
    }

    public static function catchUpOpen(\DateTimeImmutable $now): bool
    {
        return (int) $now->setTimezone(new \DateTimeZone(self::ZONE))->format('j') <= self::CATCH_UP_LAST_DAY;
    }

    public function previous(): self
    {
        return new self($this->firstDay->modify('-1 month'));
    }

    public function next(): self
    {
        return new self($this->firstDay->modify('+1 month'));
    }

    public function firstDay(): \DateTimeImmutable
    {
        return $this->firstDay;
    }

    public function lastDay(): \DateTimeImmutable
    {
        return $this->firstDay->modify('last day of this month');
    }

    /**
     * The first day as `Y-m-d`, the value of `portal_owner_reports.report_month`.
     */
    public function sql(): string
    {
        return $this->firstDay->format('Y-m-d');
    }

    /**
     * «Oktober 2026».
     */
    public function label(): string
    {
        $formatter = new \IntlDateFormatter('de_CH', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, self::ZONE, \IntlDateFormatter::GREGORIAN, 'LLLL y');
        $label = $formatter->format($this->firstDay);
        \assert(\is_string($label));

        return $label;
    }

    private static function of(int $year, int $month): self
    {
        return new self(new \DateTimeImmutable(\sprintf('%04d-%02d-01 00:00:00', $year, $month), new \DateTimeZone(self::ZONE)));
    }
}
