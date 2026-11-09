<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries;

use App\Inquiries\ValueObject\BriefRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F13: the `nr` value of /sammelanfrage and the Zurich day from which a venue's daily cap counts.
 */
final class BriefRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, list<int>}>
     */
    public static function numberValues(): iterable
    {
        yield 'comma list in order' => ['12,3,7', [12, 3, 7]];
        yield 'blanks around a number' => [' 4 , 5 ', [4, 5]];
        yield 'list of strings' => [['8', '2'], [8, 2]];
        yield 'duplicates keep the first place' => ['3,1,3,2,1', [3, 1, 2]];
        yield 'junk is dropped' => ['1,abc,2x,,1.5,-,3', [1, 3]];
        yield 'zero and negative are dropped' => ['0,-4,5,007', [5]];
        yield 'ten digits is no venue number' => ['1234567890,6', [6]];
        yield 'non-string list items are dropped' => [[1, '2', null, ['3']], [2]];
        yield 'empty string' => ['', []];
        yield 'null' => [null, []];
        yield 'an integer is no query value' => [42, []];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('numberValues')]
    public function test_numbers_reads_a_query_value(mixed $value, array $expected): void
    {
        self::assertSame($expected, BriefRules::numbers($value));
    }

    public function test_numbers_stops_at_one_hundred(): void
    {
        $numbers = BriefRules::numbers(implode(',', range(150, 1)));

        self::assertCount(100, $numbers);
        self::assertSame(150, $numbers[0]);
        self::assertSame(51, $numbers[99]);
    }

    public function test_numbers_counts_a_duplicate_once_towards_the_hundred(): void
    {
        $numbers = BriefRules::numbers(implode(',', array_merge([1, 1, 1], range(2, 120))));

        self::assertCount(100, $numbers);
        self::assertSame(range(1, 100), $numbers);
    }

    public function test_day_start_is_midnight_in_zurich(): void
    {
        $start = BriefRules::dayStart(new \DateTimeImmutable('2026-11-09T23:30:00Z'));

        self::assertSame('2026-11-10 00:00:00 Europe/Zurich', $start->format('Y-m-d H:i:s e'));
        self::assertSame('2026-11-09T23:00:00+00:00', $start->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
    }

    public function test_day_start_in_summer_time(): void
    {
        $start = BriefRules::dayStart(new \DateTimeImmutable('2026-06-01T21:59:59Z'));

        self::assertSame('2026-06-01T00:00:00+02:00', $start->format(\DATE_ATOM));
    }
}
