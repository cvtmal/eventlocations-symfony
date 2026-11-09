<?php

declare(strict_types=1);

namespace App\Tests\Unit\Portal;

use App\Portal\ValueObject\ReportMonth;
use PHPUnit\Framework\TestCase;

/**
 * The month a report covers (G10): last month in Zurich, its intl label, and the catch-up window.
 */
final class ReportMonthTest extends TestCase
{
    public function test_before_is_the_previous_zurich_month(): void
    {
        $october = ReportMonth::before(new \DateTimeImmutable('2026-11-01 03:15:00', new \DateTimeZone('Europe/Zurich')));

        self::assertSame('2026-10-01', $october->sql());
        self::assertSame('2026-10-31', $october->lastDay()->format('Y-m-d'));
        self::assertSame('2026-11-01', $october->next()->sql());
        self::assertSame('2026-09-01', $october->previous()->sql());
        self::assertSame('Europe/Zurich', $october->firstDay()->getTimezone()->getName());
        self::assertSame('00:00', $october->firstDay()->format('H:i'));

        self::assertSame('2026-12-01', ReportMonth::before(new \DateTimeImmutable('2027-01-03 03:15:00 Europe/Zurich'))->sql());
    }

    public function test_before_reads_the_day_in_zurich_not_in_utc(): void
    {
        // 23:30 UTC on 31 October is 1 November in Zurich: the report covers October.
        self::assertSame('2026-10-01', ReportMonth::before(new \DateTimeImmutable('2026-10-31 23:30:00 UTC'))->sql());
    }

    public function test_the_label_comes_from_intl(): void
    {
        self::assertSame('Oktober 2026', ReportMonth::before(new \DateTimeImmutable('2026-11-01 03:15:00 Europe/Zurich'))->label());
        self::assertSame('Dezember 2026', ReportMonth::before(new \DateTimeImmutable('2027-01-03 03:15:00 Europe/Zurich'))->label());
        self::assertSame('März 2027', ReportMonth::fromString('2027-03')?->label());
    }

    public function test_from_string_takes_only_a_year_and_month(): void
    {
        self::assertSame('2026-10-01', ReportMonth::fromString('2026-10')?->sql());
        self::assertNull(ReportMonth::fromString('2026-13'));
        self::assertNull(ReportMonth::fromString('2026-00'));
        self::assertNull(ReportMonth::fromString('2026-1'));
        self::assertNull(ReportMonth::fromString("2026-10\n"));
        self::assertNull(ReportMonth::fromString('Oktober'));
    }

    public function test_catch_up_is_open_up_to_the_seventh(): void
    {
        self::assertTrue(ReportMonth::catchUpOpen(new \DateTimeImmutable('2026-11-01 03:15:00 Europe/Zurich')));
        self::assertTrue(ReportMonth::catchUpOpen(new \DateTimeImmutable('2026-11-07 23:59:00 Europe/Zurich')));
        self::assertFalse(ReportMonth::catchUpOpen(new \DateTimeImmutable('2026-11-08 00:00:00 Europe/Zurich')));
        self::assertFalse(ReportMonth::catchUpOpen(new \DateTimeImmutable('2026-10-31 22:30:00 UTC')));
    }
}
