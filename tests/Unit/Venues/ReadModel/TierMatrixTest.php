<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues\ReadModel;

use App\Venues\ReadModel\TierColumn;
use App\Venues\ReadModel\TierDefinition;
use App\Venues\ReadModel\TierMatrix;
use App\Venues\ReadModel\TierMatrixRow;
use App\Venues\ValueObject\TierFeature;
use PHPUnit\Framework\TestCase;

/**
 * G13: the public matrix lists a feature only while some tier offers it, and only features the site has shipped.
 */
final class TierMatrixTest extends TestCase
{
    public function test_columns_run_from_the_cheapest_tier_up(): void
    {
        $matrix = TierMatrix::fromDefinitions([self::pro(), self::free()]);

        self::assertSame(['free', 'pro'], array_map(static fn (TierColumn $column): string => $column->code, $matrix->columns));
        self::assertTrue($matrix->columns[0]->isFree());
        self::assertFalse($matrix->columns[1]->isFree());
    }

    public function test_the_seeded_tiers_give_every_shipped_row_in_order(): void
    {
        $matrix = TierMatrix::fromDefinitions([self::pro(), self::free()]);

        self::assertSame(
            [TierFeature::Promoted, TierFeature::Photos, TierFeature::Videos, TierFeature::Rooms, TierFeature::Documents, TierFeature::Contact, TierFeature::EventTypes, TierFeature::LastMinute],
            self::features($matrix),
        );
        self::assertSame([false, true], self::row($matrix, TierFeature::Promoted)->cells);
        self::assertSame([6, 60], self::row($matrix, TierFeature::Photos)->cells);
        self::assertSame([false, true], self::row($matrix, TierFeature::Contact)->cells);
        self::assertSame([3, null], self::row($matrix, TierFeature::EventTypes)->cells, 'NULL means every event type that fits');
        self::assertSame([1, 5], self::row($matrix, TierFeature::Videos)->cells);
        self::assertSame([false, true], self::row($matrix, TierFeature::LastMinute)->cells);
        self::assertSame(['Pro'], $matrix->promotedTierNames());
    }

    public function test_a_flag_off_for_every_tier_drops_its_row(): void
    {
        $matrix = TierMatrix::fromDefinitions([self::free(), self::pro(contactVisible: false)]);

        self::assertNotContains(TierFeature::Contact, self::features($matrix));
    }

    public function test_a_flag_on_for_one_tier_keeps_the_row_with_the_other_cell_off(): void
    {
        $matrix = TierMatrix::fromDefinitions([self::free(contactVisible: true), self::pro(contactVisible: false)]);

        self::assertSame([true, false], self::row($matrix, TierFeature::Contact)->cells);
    }

    public function test_a_limit_of_zero_for_every_tier_drops_its_row(): void
    {
        $matrix = TierMatrix::fromDefinitions([self::free(documentsMax: 0), self::pro(documentsMax: 0)]);

        self::assertNotContains(TierFeature::Documents, self::features($matrix));
        self::assertContains(TierFeature::Rooms, self::features($matrix));
    }

    public function test_limits_come_from_the_data(): void
    {
        $matrix = TierMatrix::fromDefinitions([self::free(), self::pro(photosMax: 12)]);

        self::assertSame([6, 12], self::row($matrix, TierFeature::Photos)->cells);
        self::assertTrue(self::row($matrix, TierFeature::Photos)->isLimit());
        self::assertFalse(self::row($matrix, TierFeature::Contact)->isLimit());
    }

    public function test_no_boost_anywhere_drops_the_promoted_row_and_names_no_tier(): void
    {
        $matrix = TierMatrix::fromDefinitions([self::free(), self::pro(rankWeight: 0)]);

        self::assertNotContains(TierFeature::Promoted, self::features($matrix));
        self::assertSame([], $matrix->promotedTierNames());
    }

    public function test_every_flag_off_leaves_no_row_but_event_types_without_a_limit(): void
    {
        $off = static fn (string $code, int $price): TierDefinition => new TierDefinition($code, ucfirst($code), $price, false, 0, 0, 0, 0, 0, false, false, false, null, 0);

        $matrix = TierMatrix::fromDefinitions([$off('free', 0), $off('pro', 19900)]);

        self::assertSame([TierFeature::EventTypes], self::features($matrix), 'NULL event types is an offer: every type that fits');
        self::assertSame([], $matrix->promotedTierNames());
    }

    public function test_unshipped_features_get_no_row_even_when_a_tier_has_them(): void
    {
        $matrix = TierMatrix::fromDefinitions([self::free(), self::pro()]);

        // Pro has the detailed report on; G10 has not shipped it. G15 (videos) and G14 (last-minute) have.
        self::assertSame(['promoted', 'photos', 'videos', 'rooms', 'documents', 'contact', 'event_types', 'last_minute'], array_map(static fn (TierFeature $feature): string => $feature->value, TierFeature::cases()));
        self::assertCount(\count(TierFeature::cases()), $matrix->rows);
    }

    public function test_a_third_tier_adds_a_column_in_price_order(): void
    {
        $team = new TierDefinition('team', 'Team', 9900, true, 5, 20, 5, 0, 2, true, false, false, 6, 0);

        $matrix = TierMatrix::fromDefinitions([self::pro(), $team, self::free()]);

        self::assertSame(['Gratis', 'Team', 'Pro'], array_map(static fn (TierColumn $column): string => $column->name, $matrix->columns));
        self::assertSame([6, 20, 60], self::row($matrix, TierFeature::Photos)->cells);
        self::assertSame(['Team', 'Pro'], $matrix->promotedTierNames());
        self::assertTrue($matrix->columns[1]->vatLine);
    }

    public function test_the_price_keeps_its_rappen_only_when_there_are_any(): void
    {
        $whole = new TierColumn('pro', 'Pro', 19900, false, true);
        $odd = new TierColumn('pro', 'Pro', 4950, false, true);

        self::assertSame([199, 0], [$whole->francs(), $whole->priceDecimals()]);
        self::assertSame([49.5, 2], [$odd->francs(), $odd->priceDecimals()]);
    }

    private static function free(bool $contactVisible = false, int $documentsMax = 1): TierDefinition
    {
        return new TierDefinition('free', 'Gratis', 0, false, 0, 6, 1, 1, $documentsMax, $contactVisible, false, false, 3, 0);
    }

    private static function pro(bool $contactVisible = true, int $documentsMax = 5, int $photosMax = 60, int $rankWeight = 10): TierDefinition
    {
        return new TierDefinition('pro', 'Pro', 19900, false, $rankWeight, $photosMax, 20, 5, $documentsMax, $contactVisible, true, true, null, 0);
    }

    /**
     * @return list<TierFeature>
     */
    private static function features(TierMatrix $matrix): array
    {
        return array_map(static fn (TierMatrixRow $row): TierFeature => $row->feature, $matrix->rows);
    }

    private static function row(TierMatrix $matrix, TierFeature $feature): TierMatrixRow
    {
        foreach ($matrix->rows as $row) {
            if ($row->feature === $feature) {
                return $row;
            }
        }
        self::fail('No row for '.$feature->value);
    }
}
