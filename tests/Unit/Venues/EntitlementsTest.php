<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\Entitlements;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a venue may show and do (F12, D15): the limits and flags of one tier row, read through the shape the
 * `EntitlementResolver` selects. The numbers are the seeded Free and Pro rows.
 */
final class EntitlementsTest extends TestCase
{
    public function test_a_pro_row_becomes_entitlements_with_all_event_types(): void
    {
        $entitlements = Entitlements::fromRow(self::row());

        self::assertEquals(new Entitlements('pro', 'Pro', 10, 60, 20, 5, 5, true, true, true, null), $entitlements);
        self::assertNull($entitlements->eventTypesMax, 'NULL means every event type');
    }

    public function test_a_free_row_keeps_its_ceilings_and_hides_the_contact_data(): void
    {
        $entitlements = Entitlements::fromRow(self::row([
            'tier_code' => 'free', 'tier_name' => 'Free', 'rank_weight' => 0, 'photos_max' => 6, 'rooms_max' => 1, 'videos_max' => 1, 'documents_max' => 1,
            'contact_visible' => false, 'last_minute_allowed' => false, 'detailed_report' => false, 'event_types_max' => 3,
        ]));

        self::assertEquals(new Entitlements('free', 'Free', 0, 6, 1, 1, 1, false, false, false, 3), $entitlements);
        self::assertSame(3, $entitlements->eventTypesMax);
    }

    public function test_flags_may_arrive_as_integers(): void
    {
        $entitlements = Entitlements::fromRow(self::row(['contact_visible' => 1, 'last_minute_allowed' => 0, 'detailed_report' => 1]));

        self::assertTrue($entitlements->contactVisible);
        self::assertFalse($entitlements->lastMinuteAllowed);
        self::assertTrue($entitlements->detailedReport);
    }

    public function test_a_limit_that_is_no_integer_is_refused(): void
    {
        $this->expectException(\AssertionError::class);

        Entitlements::fromRow(self::row(['photos_max' => '6']));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function rankWeights(): iterable
    {
        yield 'no boost' => [0, false];
        yield 'the smallest boost' => [1, true];
        yield 'Pro' => [10, true];
        yield 'a large weight' => [32767, true];
    }

    #[DataProvider('rankWeights')]
    public function test_only_a_weight_above_zero_is_promoted(int $rankWeight, bool $promoted): void
    {
        self::assertSame($promoted, Entitlements::fromRow(self::row(['rank_weight' => $rankWeight]))->isPromoted());
    }

    /**
     * @return iterable<string, array{?int, int, bool}> event_types_max, count after the save, allowed
     */
    public static function eventTypeCounts(): iterable
    {
        yield 'none selected under a limit' => [3, 0, true];
        yield 'below the limit' => [3, 2, true];
        yield 'at the limit' => [3, 3, true];
        yield 'one over the limit' => [3, 4, false];
        yield 'far over the limit' => [3, 20, false];
        yield 'a limit of one: one' => [1, 1, true];
        yield 'a limit of one: two' => [1, 2, false];
        yield 'no limit: none' => [null, 0, true];
        yield 'no limit: a few' => [null, 3, true];
        yield 'no limit: all of them' => [null, 500, true];
    }

    #[DataProvider('eventTypeCounts')]
    public function test_the_event_type_limit_is_inclusive_and_null_means_all(?int $eventTypesMax, int $count, bool $allowed): void
    {
        $entitlements = Entitlements::fromRow(self::row(['event_types_max' => $eventTypesMax]));

        self::assertSame($allowed, $entitlements->allowsEventTypes($count));
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function row(array $override = []): array
    {
        return $override + [
            'tier_code' => 'pro',
            'tier_name' => 'Pro',
            'rank_weight' => 10,
            'photos_max' => 60,
            'rooms_max' => 20,
            'videos_max' => 5,
            'documents_max' => 5,
            'contact_visible' => true,
            'last_minute_allowed' => true,
            'detailed_report' => true,
            'event_types_max' => null,
        ];
    }
}
