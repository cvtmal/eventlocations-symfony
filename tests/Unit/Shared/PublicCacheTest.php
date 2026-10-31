<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Http\PublicCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicCacheTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function midnights(): iterable
    {
        yield 'one second before midnight' => ['2026-10-30 23:59:59 Europe/Zurich', 1];
        yield 'at midnight a whole day' => ['2026-10-30 00:00:00 Europe/Zurich', 86400];
        yield 'at ten in the evening' => ['2026-10-30 22:00:00 Europe/Zurich', 7200];
        yield 'the 25-hour day at the end of summer time caps at a day' => ['2026-10-25 00:00:00 Europe/Zurich', 86400];
        yield 'the evening of the 25-hour day' => ['2026-10-25 22:00:00 Europe/Zurich', 7200];
        yield 'the 23-hour day at the start of summer time' => ['2027-03-28 00:00:00 Europe/Zurich', 82800];
        yield 'a UTC time is read in Zurich' => ['2026-10-30 21:00:00 UTC', 7200];
    }

    #[DataProvider('midnights')]
    public function test_seconds_until_the_next_zurich_midnight(string $now, int $expected): void
    {
        self::assertSame($expected, PublicCache::secondsUntilMidnight(new \DateTimeImmutable($now)));
    }

    public function test_a_page_is_shared_for_a_day_and_served_stale_on_errors(): void
    {
        self::assertSame(
            ['public' => true, 's_maxage' => 86400, 'stale_while_revalidate' => 86400, 'stale_if_error' => 86400],
            PublicCache::page(),
        );
    }

    public function test_a_listing_expires_at_midnight(): void
    {
        self::assertSame(
            ['s_maxage' => 7200, 'public' => true, 'stale_while_revalidate' => 86400, 'stale_if_error' => 86400],
            PublicCache::listing(new \DateTimeImmutable('2026-10-30 22:00:00 Europe/Zurich')),
        );
    }
}
