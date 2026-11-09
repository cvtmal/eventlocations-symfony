<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use App\Venues\ReadModel\VenuePublishGaps;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class VenuePublishGapsTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    public function test_a_complete_venue_lacks_nothing(): void
    {
        self::assertSame([], $this->gaps()->missing($this->complete()->id()));
    }

    public function test_it_names_each_gap_in_the_rules_order(): void
    {
        $venue = VenueFactory::new()->withoutInquiryEmail()->create(['status' => VenueStatus::Unpublished]);

        // F14 (D54): nobody manages it, so the missing description is a gap too (DraftRuleTest).
        self::assertSame(['summary', 'main_building', 'photo', 'inquiry_email', 'description'], $this->gaps()->missing($venue->id()));
    }

    public function test_a_room_photo_does_not_count(): void
    {
        $venue = $this->complete();
        $this->connection->executeStatement('DELETE FROM venue_photos WHERE venue_id = ?', [$venue->id()]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => RoomFactory::createOne(['venue' => $venue])]);

        self::assertSame(['photo'], $this->gaps()->missing($venue->id()));
    }

    public function test_a_blank_summary_counts_as_missing(): void
    {
        $venue = $this->complete();
        $this->connection->executeStatement("UPDATE venue_translations SET summary = '   ' WHERE venue_id = ?", [$venue->id()]);

        self::assertSame(['summary'], $this->gaps()->missing($venue->id()));
    }

    /**
     * D60: D2 promises an inquiry form on every page, so a venue without an inquiry address cannot go online.
     */
    public function test_a_missing_inquiry_address_is_a_gap(): void
    {
        $venue = $this->complete();
        $this->connection->executeStatement('UPDATE venues SET inquiry_email = NULL WHERE id = ?', [$venue->id()]);

        self::assertSame(['inquiry_email'], $this->gaps()->missing($venue->id()));
    }

    private function gaps(): VenuePublishGaps
    {
        $gaps = self::getContainer()->get(VenuePublishGaps::class);
        self::assertInstanceOf(VenuePublishGaps::class, $gaps);

        return $gaps;
    }

    private function complete(): Venue
    {
        $venue = VenueFactory::createOne(['status' => VenueStatus::Unpublished]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'summary' => 'Ein Ort für die Hochzeit.']);
        VenueTermFactory::new()->term(TermGroup::Building, 'castle')->main()->create(['venue' => $venue]);
        VenuePhotoFactory::createOne(['venue' => $venue]);

        return $venue;
    }
}
