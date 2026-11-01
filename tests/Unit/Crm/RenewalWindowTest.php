<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ValueObject\RenewalWindow;
use PHPUnit\Framework\TestCase;

/**
 * D22: today through 60 days on, both included, and "today" is Zurich's. D24: a renewal task's due date.
 */
final class RenewalWindowTest extends TestCase
{
    public function test_it_holds_day_0_and_day_60_and_nothing_else(): void
    {
        $window = RenewalWindow::at(new \DateTimeImmutable('2026-11-01 10:00:00 UTC'));

        self::assertSame('2026-11-01', $window->first);
        self::assertSame('2026-12-31', $window->last);
        self::assertTrue($window->contains(new \DateTimeImmutable('2026-11-01')));
        self::assertTrue($window->contains(new \DateTimeImmutable('2026-12-31')));
        self::assertFalse($window->contains(new \DateTimeImmutable('2026-10-31')));
        self::assertFalse($window->contains(new \DateTimeImmutable('2027-01-01')));
    }

    public function test_today_turns_at_midnight_in_zurich(): void
    {
        self::assertSame('2026-11-01', RenewalWindow::at(new \DateTimeImmutable('2026-10-31 23:30:00 UTC'))->first);
        self::assertSame('2026-10-31', RenewalWindow::at(new \DateTimeImmutable('2026-10-31 22:30:00 UTC'))->first);
    }

    public function test_a_renewal_task_is_due_30_days_before_the_end(): void
    {
        $now = new \DateTimeImmutable('2026-11-01 10:00:00 UTC');

        self::assertSame('2026-12-01', RenewalWindow::taskDueOn(new \DateTimeImmutable('2026-12-31'), $now)->format('Y-m-d'));
        self::assertSame('2026-11-02', RenewalWindow::taskDueOn(new \DateTimeImmutable('2026-12-02'), $now)->format('Y-m-d'));
    }

    public function test_a_renewal_task_is_not_due_before_today_in_zurich(): void
    {
        $end = new \DateTimeImmutable('2026-11-20');

        self::assertSame('2026-11-01', RenewalWindow::taskDueOn($end, new \DateTimeImmutable('2026-10-31 23:30:00 UTC'))->format('Y-m-d'));
        self::assertSame('2026-10-31', RenewalWindow::taskDueOn($end, new \DateTimeImmutable('2026-10-31 22:30:00 UTC'))->format('Y-m-d'));
    }
}
