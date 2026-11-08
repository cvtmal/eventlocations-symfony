<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ReadModel\DealFormOption;
use App\Crm\ReadModel\DealRecordQuery;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\ReadModel\TierOptionsQuery;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\TierLabel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The deal record's one statement (D20) as far as tiers go (F12): the deal's and its Eintrag's tier as labels, and the
 * tiers the Tier field offers, which ride in the same statement.
 */
final class DealRecordQueryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_it_reads_the_deals_tier_and_its_eintrags_as_labels(): void
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['tier' => KnownTiers::PRO]);
        $company = CompanyFactory::new()->withVenue($venue)->create();
        $deal = DealFactory::createOne(['company' => $company, 'venue' => $venue, 'tier' => KnownTiers::FREE]);

        $record = $this->query()->find($deal->id());

        self::assertNotNull($record);
        self::assertEquals(new TierLabel('free', 'Gratis', false), $record->tier);
        self::assertEquals(new TierLabel('pro', 'Pro', true), $record->venue?->tier);
    }

    public function test_a_deal_without_a_tier_has_none(): void
    {
        $deal = DealFactory::createOne();

        self::assertNull($this->query()->find($deal->id())?->tier);
    }

    public function test_the_tier_options_are_the_options_querys_in_its_order(): void
    {
        // Equal rank and price are told apart by code, and a tier added later is on the list at once.
        $this->connection->executeStatement("INSERT INTO tiers (id, code, name, price_rappen, rank_weight, photos_max, rooms_max, videos_max, documents_max, contact_visible, last_minute_allowed, detailed_report, event_types_max) VALUES (gen_random_uuid(), 'team', 'Team', 49900, 5, 20, 5, 2, 2, TRUE, FALSE, FALSE, NULL), (gen_random_uuid(), 'plus', 'Plus', 49900, 5, 20, 5, 2, 2, TRUE, FALSE, FALSE, NULL), (gen_random_uuid(), 'aaa', 'Aaa', 0, 0, 6, 1, 1, 1, FALSE, FALSE, FALSE, 3)");
        $deal = DealFactory::createOne();

        $record = $this->query()->find($deal->id());

        self::assertNotNull($record);
        $choices = $this->options()->choices();
        self::assertSame(['pro', 'plus', 'team', 'aaa', 'free'], array_values($choices));
        self::assertEquals(
            array_map(static fn (string $name, string $code): DealFormOption => new DealFormOption($code, $name), array_keys($choices), array_values($choices)),
            $record->tierOptions,
        );
    }

    private function query(): DealRecordQuery
    {
        $query = self::getContainer()->get(DealRecordQuery::class);
        self::assertInstanceOf(DealRecordQuery::class, $query);

        return $query;
    }

    private function options(): TierOptionsQuery
    {
        $options = self::getContainer()->get(TierOptionsQuery::class);
        self::assertInstanceOf(TierOptionsQuery::class, $options);

        return $options;
    }
}
