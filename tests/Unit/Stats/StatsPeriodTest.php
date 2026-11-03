<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stats;

use App\Stats\ValueObject\StatsPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatsPeriodTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, StatsPeriod}>
     */
    public static function queries(): iterable
    {
        yield '7' => ['7', StatsPeriod::Days7];
        yield '30' => ['30', StatsPeriod::Days30];
        yield '90' => ['90', StatsPeriod::Days90];
        yield '365' => ['365', StatsPeriod::Days365];
        yield 'missing' => [null, StatsPeriod::Days30];
        yield 'empty' => ['', StatsPeriod::Days30];
        yield 'text' => ['abc', StatsPeriod::Days30];
        yield 'unknown number' => ['14', StatsPeriod::Days30];
        yield 'number with a tail' => ['7abc', StatsPeriod::Days30];
        yield 'negative' => ['-7', StatsPeriod::Days30];
        yield 'decimal' => ['7.0', StatsPeriod::Days30];
    }

    #[DataProvider('queries')]
    public function test_from_query_falls_back_to_30_days(?string $query, StatsPeriod $expected): void
    {
        self::assertSame($expected, StatsPeriod::fromQuery($query));
    }

    /**
     * @return iterable<string, array{StatsPeriod, string}>
     */
    public static function firstDays(): iterable
    {
        yield '7 days' => [StatsPeriod::Days7, '2026-10-28'];
        yield '30 days' => [StatsPeriod::Days30, '2026-10-05'];
        yield '90 days' => [StatsPeriod::Days90, '2026-08-06'];
        yield '365 days' => [StatsPeriod::Days365, '2025-11-04'];
    }

    #[DataProvider('firstDays')]
    public function test_the_range_ends_today_inclusive(StatsPeriod $period, string $expected): void
    {
        $today = new \DateTimeImmutable('2026-11-03 14:05:09', new \DateTimeZone('Europe/Zurich'));

        $firstDay = $period->firstDay($today);

        self::assertSame($expected.' 00:00:00', $firstDay->format('Y-m-d H:i:s'));
        self::assertSame($period->value, (int) $firstDay->diff($today->setTime(0, 0))->days + 1);
        self::assertSame('Europe/Zurich', $firstDay->getTimezone()->getName());
    }
}
