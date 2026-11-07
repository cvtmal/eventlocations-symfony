<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stats;

use App\Stats\ValueObject\FunnelWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FunnelWindowTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, FunnelWindow}>
     */
    public static function queries(): iterable
    {
        yield '4' => ['4', FunnelWindow::Weeks4];
        yield '12' => ['12', FunnelWindow::Weeks12];
        yield '26' => ['26', FunnelWindow::Weeks26];
        yield '52' => ['52', FunnelWindow::Weeks52];
        yield 'missing' => [null, FunnelWindow::Weeks12];
        yield 'empty' => ['', FunnelWindow::Weeks12];
        yield 'text' => ['abc', FunnelWindow::Weeks12];
        yield 'unknown number' => ['8', FunnelWindow::Weeks12];
        yield 'number with a tail' => ['4abc', FunnelWindow::Weeks12];
        yield 'negative' => ['-4', FunnelWindow::Weeks12];
    }

    #[DataProvider('queries')]
    public function test_from_query_falls_back_to_12_weeks(?string $query, FunnelWindow $expected): void
    {
        self::assertSame($expected, FunnelWindow::fromQuery($query));
    }

    /**
     * @return iterable<string, array{FunnelWindow, string, string}>
     */
    public static function firstWeeks(): iterable
    {
        yield 'a Wednesday' => [FunnelWindow::Weeks4, '2026-11-04 15:30', '2026-10-12 00:00'];
        yield 'the Monday itself' => [FunnelWindow::Weeks4, '2026-11-02 00:00', '2026-10-12 00:00'];
        yield 'a Sunday belongs to the week that began on Monday' => [FunnelWindow::Weeks4, '2026-11-08 23:59', '2026-10-12 00:00'];
        yield '12 weeks' => [FunnelWindow::Weeks12, '2026-11-04 15:30', '2026-08-17 00:00'];
        yield '52 weeks' => [FunnelWindow::Weeks52, '2026-11-04 15:30', '2025-11-10 00:00'];
    }

    #[DataProvider('firstWeeks')]
    public function test_first_week_is_the_monday_of_the_oldest_week(FunnelWindow $window, string $today, string $expected): void
    {
        $zone = new \DateTimeZone('Europe/Zurich');

        self::assertSame($expected, $window->firstWeek(new \DateTimeImmutable($today, $zone))->format('Y-m-d H:i'));
    }

    public function test_first_week_keeps_local_midnight_across_the_clock_change(): void
    {
        // The clocks went back on 2026-10-25: a week count in seconds would land on 23:00.
        $first = FunnelWindow::Weeks4->firstWeek(new \DateTimeImmutable('2026-11-04 12:00', new \DateTimeZone('Europe/Zurich')));
        $before = FunnelWindow::Weeks12->firstWeek(new \DateTimeImmutable('2026-11-04 12:00', new \DateTimeZone('Europe/Zurich')));

        self::assertSame('00:00', $first->format('H:i'));
        self::assertSame('00:00', $before->format('H:i'));
        self::assertSame('+02:00', $before->format('P'), 'August is summer time');
    }
}
