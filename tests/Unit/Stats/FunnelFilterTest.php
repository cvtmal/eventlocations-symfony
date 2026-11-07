<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stats;

use App\Stats\ValueObject\FunnelFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FunnelFilterTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, bool, ?string}>
     */
    public static function queries(): iterable
    {
        yield 'missing is all' => [null, false, null];
        yield 'empty is all' => ['', false, null];
        yield 'a dash is without a campaign' => ['-', true, null];
        yield 'a code' => ['zh-brief-nov', false, 'zh-brief-nov'];
        yield 'a code with a digit first' => ['2026_nov', false, '2026_nov'];
        yield 'a space is not a code' => ['zh brief', false, null];
        yield 'an address is not a code' => ['max@example.com', false, null];
        yield 'a leading dash is not a code' => ['-zh', false, null];
        yield 'a trailing newline is not a code' => ["zh\n", false, null];
        yield '40 characters' => [str_repeat('a', 40), false, str_repeat('a', 40)];
        yield '41 characters' => [str_repeat('a', 41), false, null];
    }

    #[DataProvider('queries')]
    public function test_from_query_reads_the_campaign_filter(?string $query, bool $without, ?string $campaign): void
    {
        $filter = FunnelFilter::fromQuery($query);

        self::assertSame($without, $filter->withoutCampaign);
        self::assertSame($campaign, $filter->campaign);
    }

    public function test_the_query_value_round_trips(): void
    {
        self::assertNull(FunnelFilter::all()->queryValue());
        self::assertSame('-', FunnelFilter::withoutCampaign()->queryValue());
        self::assertSame('ag-mail', FunnelFilter::campaign('ag-mail')->queryValue());
        self::assertSame('ag-mail', FunnelFilter::fromQuery(FunnelFilter::campaign('ag-mail')->queryValue())->campaign);
    }
}
