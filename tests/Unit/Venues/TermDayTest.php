<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\TermDay;
use PHPUnit\Framework\TestCase;

/**
 * Terms are measured in Zurich calendar days (G9), whatever zone the instant is in.
 */
final class TermDayTest extends TestCase
{
    public function test_the_day_is_the_zurich_day_of_the_instant(): void
    {
        self::assertSame('2026-11-07', TermDay::sql(new \DateTimeImmutable('2026-11-07 22:59:59 UTC')));
        self::assertSame('2026-11-08', TermDay::sql(new \DateTimeImmutable('2026-11-07 23:00:00 UTC')));
        self::assertSame('2026-07-01', TermDay::sql(new \DateTimeImmutable('2026-06-30 22:00:00 UTC')), 'summer time');
        self::assertSame('00:00:00', TermDay::of(new \DateTimeImmutable('2026-11-08 15:30:00 Europe/Zurich'))->format('H:i:s'));
    }

    public function test_one_year_ends_the_day_before_the_same_date(): void
    {
        self::assertSame('2027-11-07', TermDay::oneYearFrom(new \DateTimeImmutable('2026-11-08 13:00'))->format('Y-m-d'));
        self::assertSame('2029-02-28', TermDay::oneYearFrom(new \DateTimeImmutable('2028-02-29'))->format('Y-m-d'));
    }
}
