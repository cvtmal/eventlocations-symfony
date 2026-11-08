<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ReadModel\CompanyRecordQuery;
use App\Factory\CompanyFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\TierLabel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The company record's one statement (D20) as far as tiers go (F12): its Eintrag's tier as a label, and the names of
 * every tier, which the timeline's sentence about a change of tier needs, in the same statement.
 */
final class CompanyRecordQueryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_it_reads_the_eintrags_tier_as_a_label(): void
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['tier' => KnownTiers::PRO]);
        $company = CompanyFactory::new()->withVenue($venue)->create();

        $record = $this->query()->find($company->id());

        self::assertEquals(TierLabel::of('pro', 'Pro', 10), $record?->venue?->tier);
    }

    public function test_a_location_without_an_eintrag_has_no_tier_label(): void
    {
        $company = CompanyFactory::createOne();

        self::assertNull($this->query()->find($company->id())?->venue);
    }

    public function test_it_names_every_tier_by_its_code(): void
    {
        $this->connection->executeStatement("UPDATE tiers SET name = 'Premium' WHERE code = 'pro'");
        $this->connection->executeStatement("INSERT INTO tiers (id, code, name, price_rappen, rank_weight, photos_max, rooms_max, videos_max, documents_max, contact_visible, last_minute_allowed, detailed_report, event_types_max) VALUES (gen_random_uuid(), 'team', 'Team', 49900, 5, 20, 5, 2, 2, TRUE, FALSE, FALSE, NULL)");
        $company = CompanyFactory::createOne();

        // With or without an Eintrag: the names do not hang on the venue.
        self::assertEquals(['pro' => 'Premium', 'free' => 'Free', 'team' => 'Team'], $this->query()->find($company->id())?->tierNames);
    }

    private function query(): CompanyRecordQuery
    {
        $query = self::getContainer()->get(CompanyRecordQuery::class);
        self::assertInstanceOf(CompanyRecordQuery::class, $query);

        return $query;
    }
}
