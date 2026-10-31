<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ValueObject\ActivityDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActivityDateTest extends TestCase
{
    public function test_a_local_time_is_read_in_zurich(): void
    {
        $date = ActivityDate::parse('2026-10-31T14:05');

        self::assertNotNull($date);
        self::assertSame('2026-10-31T13:05:00+00:00', $date->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
    }

    public function test_summer_time_is_read_in_zurich(): void
    {
        $date = ActivityDate::parse('2026-07-01T14:05');

        self::assertNotNull($date);
        self::assertSame('2026-07-01T12:05:00+00:00', $date->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
    }

    public function test_local_gives_the_format_parse_takes(): void
    {
        self::assertSame('2026-10-31T14:05', ActivityDate::local(new \DateTimeImmutable('2026-10-31T13:05:00+00:00')));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notDates(): iterable
    {
        yield 'null' => [null];
        yield 'an array' => [['2026-10-31T14:05']];
        yield 'a number' => [20261031];
        yield 'empty' => [''];
        yield 'a date alone' => ['2026-10-31'];
        yield 'seconds' => ['2026-10-31T14:05:00'];
        yield 'an offset' => ['2026-10-31T14:05+01:00'];
        yield 'a trailing line break' => ["2026-10-31T14:05\n"];
        yield 'no such day' => ['2026-02-31T14:05'];
        yield 'no such hour' => ['2026-10-31T24:05'];
        yield 'the hour the clock skips' => ['2026-03-29T02:30'];
        yield 'year zero' => ['0000-01-01T00:00'];
        yield 'before Zurich had a whole-minute offset' => ['1850-01-01T12:00'];
        yield 'far ahead' => ['2100-01-01T12:00'];
    }

    #[DataProvider('notDates')]
    public function test_anything_else_is_no_date(mixed $value): void
    {
        self::assertNull(ActivityDate::parse($value));
    }
}
