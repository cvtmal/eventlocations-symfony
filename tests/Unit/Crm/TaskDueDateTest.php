<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ValueObject\TaskDue;
use App\Crm\ValueObject\TaskDueDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CRM-5: a due date is a calendar day, and "today" is Zurich's.
 */
final class TaskDueDateTest extends TestCase
{
    public function test_a_date_keeps_its_day(): void
    {
        self::assertSame('2026-11-03', TaskDueDate::parse('2026-11-03')?->format('Y-m-d'));
        self::assertSame('2028-02-29', TaskDueDate::parse('2028-02-29')?->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function noDates(): iterable
    {
        yield 'a short month' => ['2026-02-31'];
        yield 'not a leap year' => ['2026-02-29'];
        yield 'a month 13' => ['2026-13-01'];
        yield 'with a time' => ['2026-11-03T10:00'];
        yield 'a trailing line' => ["2026-11-03\n"];
        yield 'Swiss order' => ['03.11.2026'];
        yield 'year 0' => ['0000-01-01'];
        yield 'too far' => ['2100-01-01'];
        yield 'not a string' => [20261103];
        yield 'empty' => [''];
        yield 'nothing' => [null];
    }

    #[DataProvider('noDates')]
    public function test_anything_else_is_no_date(mixed $value): void
    {
        self::assertNull(TaskDueDate::parse($value));
    }

    public function test_today_is_the_day_in_zurich_whatever_the_zone_of_the_clock(): void
    {
        self::assertSame('2026-11-01', TaskDueDate::today(new \DateTimeImmutable('2026-10-31 23:30:00 UTC')));
        self::assertSame('2026-10-31', TaskDueDate::today(new \DateTimeImmutable('2026-10-31 22:30:00 UTC')));
        self::assertSame('2026-11-01', TaskDueDate::today(new \DateTimeImmutable('2026-11-01 00:10:00', new \DateTimeZone('Europe/Zurich'))));
    }

    public function test_a_day_stands_against_today(): void
    {
        self::assertSame(TaskDue::Overdue, TaskDue::of('2026-10-30', '2026-10-31'));
        self::assertSame(TaskDue::Overdue, TaskDue::of('2025-12-31', '2026-01-01'));
        self::assertSame(TaskDue::Today, TaskDue::of('2026-10-31', '2026-10-31'));
        self::assertSame(TaskDue::Later, TaskDue::of('2026-11-01', '2026-10-31'));
    }
}
