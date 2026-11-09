<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\VenueFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\ReadModel\TierDefinition;
use App\Venues\ReadModel\TierDefinitionsQuery;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The read model of the screen "Tarife" (F12) against the rows the migration seeds: best first, with the number of
 * venues on each tier.
 */
final class TierDefinitionsQueryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_it_lists_the_seeded_tiers_best_first(): void
    {
        $definitions = $this->query()->all();

        self::assertEquals(
            [
                new TierDefinition('pro', 'Pro', 19900, false, 10, 60, 20, 5, 5, true, true, true, null, $definitions[0]->venueCount),
                new TierDefinition('free', 'Gratis', 0, false, 0, 6, 1, 1, 1, false, false, false, 3, $definitions[1]->venueCount),
            ],
            $definitions,
        );
    }

    public function test_it_counts_the_venues_of_each_tier(): void
    {
        $before = $this->counts();
        VenueFactory::new()->withoutLocation()->many(2)->create(['tier' => KnownTiers::PRO]);
        VenueFactory::new()->withoutLocation()->many(3)->create(['tier' => KnownTiers::FREE]);

        $after = $this->counts();

        self::assertSame($before['pro'] + 2, $after['pro']);
        self::assertSame($before['free'] + 3, $after['free']);
    }

    public function test_a_code_gives_its_definition_and_an_unknown_one_none(): void
    {
        $pro = $this->query()->byCode('pro');

        self::assertNotNull($pro);
        self::assertSame('Pro', $pro->name);
        self::assertSame(19900, $pro->priceRappen);
        self::assertNull($pro->eventTypesMax);
        self::assertNull($this->query()->byCode('gold'));
        self::assertNull($this->query()->byCode(''));
    }

    public function test_a_name_gives_the_code_of_its_tier_as_the_unique_key_sees_it(): void
    {
        self::assertSame('pro', $this->query()->codeByName('Pro'));
        self::assertSame('free', $this->query()->codeByName('Gratis'));
        // The key is an exact match: another case, a space or a part of a name is no match.
        self::assertNull($this->query()->codeByName('pro'));
        self::assertNull($this->query()->codeByName('Pro '));
        self::assertNull($this->query()->codeByName('Pr'));
        self::assertNull($this->query()->codeByName(''));
    }

    public function test_a_higher_rank_weight_lists_first_whatever_the_code(): void
    {
        $this->connection->executeStatement("UPDATE tiers SET rank_weight = 30 WHERE code = 'free'");

        self::assertSame(['free', 'pro'], array_map(static fn (TierDefinition $tier): string => $tier->code, $this->query()->all()));
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = [];
        foreach ($this->query()->all() as $tier) {
            $counts[$tier->code] = $tier->venueCount;
        }

        return $counts;
    }

    private function query(): TierDefinitionsQuery
    {
        return new TierDefinitionsQuery($this->connection);
    }
}
