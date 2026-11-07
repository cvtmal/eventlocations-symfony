<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Command\PublishVenue;
use App\Venues\Command\UnpublishVenue;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * G8: every transition to online is the funnel step `venue_published`, recorded after the commit and not for a refused
 * or a repeated publish.
 */
final class PublishVenueFunnelTest extends KernelTestCase
{
    use DispatchesVenueCommands;
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    public function test_publishing_records_one_step_of_that_venue(): void
    {
        $venue = $this->publishable();
        $this->connection->executeStatement('DELETE FROM funnel_events');

        $this->dispatch(new PublishVenue($venue->number()));

        self::assertSame(
            [['event' => 'venue_published', 'venue_id' => $venue->id(), 'campaign_code' => null]],
            $this->connection->fetchAllAssociative('SELECT event, venue_id, campaign_code FROM funnel_events'),
        );
    }

    public function test_publishing_an_online_venue_records_nothing_more(): void
    {
        $venue = $this->publishable();
        $this->dispatch(new PublishVenue($venue->number()));
        $this->connection->executeStatement('DELETE FROM funnel_events');

        $this->dispatch(new PublishVenue($venue->number()));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM funnel_events'));
    }

    public function test_a_refused_publish_records_nothing(): void
    {
        $venue = $this->publishable();
        $this->connection->executeStatement('DELETE FROM venue_photos WHERE venue_id = ?', [$venue->id()]);
        $this->connection->executeStatement('DELETE FROM funnel_events');

        $this->violation(new PublishVenue($venue->number()));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM funnel_events'));
    }

    public function test_putting_a_venue_back_online_is_a_step_again(): void
    {
        $venue = $this->publishable();
        $this->dispatch(new PublishVenue($venue->number()));
        $this->dispatch(new UnpublishVenue($venue->number()));
        $this->connection->executeStatement('DELETE FROM funnel_events');

        $this->dispatch(new PublishVenue($venue->number()));

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events WHERE event = 'venue_published' AND venue_id = ?", [$venue->id()]));
    }

    /**
     * Offline, with a summary, a main building and a gallery photo.
     */
    private function publishable(): Venue
    {
        $venue = VenueFactory::createOne(['status' => VenueStatus::Unpublished]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'bereit', 'summary' => 'Ein Ort für den Anlass.']);
        VenueTermFactory::new()->term(TermGroup::Building, 'castle')->main()->create(['venue' => $venue]);
        VenuePhotoFactory::createOne(['venue' => $venue]);

        return $venue;
    }
}
