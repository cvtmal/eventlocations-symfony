<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\SubmissionFactory;
use App\Factory\VenueFactory;
use App\Portal\Command\CreateOwnLocation;
use App\Portal\Entity\PortalUser;
use App\Portal\Exception\TooManyUnfinishedLocations;
use App\Portal\Repository\SubmissionRepository;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Command\DeleteVenue;
use App\Venues\Entity\Venue;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * The create path of #45: one transaction over `CreateVenue` and `RelocateVenue`, and the limit of unfinished
 * locations in front of it.
 */
final class CreateOwnLocationTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    private const string TOWN = '8706 Meilen';

    public function test_it_creates_an_unpublished_basic_location_with_active_access_and_a_draft(): void
    {
        $manager = PortalUserFactory::createOne();

        $number = $this->create($manager);

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT v.name, v.tier, v.status, v.capacity_max, a.status AS access, a.confirmed_at IS NOT NULL AS confirmed, s.status AS submission
                FROM venues v
                JOIN portal_venue_access a ON a.venue_id = v.id AND a.portal_user_id = ?
                JOIN portal_submissions s ON s.venue_id = v.id
                WHERE v.number = ?
                SQL,
            [$manager->id(), $number],
        );
        self::assertSame([
            'name' => 'Seehaus Meilen',
            'tier' => 'basic',
            'status' => 'unpublished',
            'capacity_max' => 120,
            'access' => 'active',
            'confirmed' => true,
            'submission' => 'draft',
        ], $row);
    }

    public function test_it_takes_the_town_centre(): void
    {
        $number = $this->create(PortalUserFactory::createOne());

        $row = $this->connection->fetchAssociative(
            'SELECT v.zip, t.name, v.coordinates_from_town, v.lat = t.lat AS same_lat, v.lng = t.lng AS same_lng FROM venues v JOIN towns t ON t.id = v.town_id WHERE v.number = ?',
            [$number],
        );
        self::assertSame(['zip' => '8706', 'name' => 'Meilen', 'coordinates_from_town' => true, 'same_lat' => true, 'same_lng' => true], $row);
    }

    public function test_the_relocate_token_survives_a_rounded_second(): void
    {
        self::mockTime('2026-11-02 12:00:00.7 UTC');

        $number = $this->create(PortalUserFactory::createOne());

        self::assertTrue($this->connection->fetchOne('SELECT coordinates_from_town FROM venues WHERE number = ?', [$number]));
    }

    public function test_an_unknown_town_writes_nothing_and_surfaces_the_rule(): void
    {
        $manager = PortalUserFactory::createOne();

        try {
            $this->create($manager, '9999 Nirgendwo');
            self::fail('The town does not exist.');
        } catch (HandlerFailedException $failure) {
            $violation = $failure->getPrevious();
            self::assertInstanceOf(VenueRuleViolation::class, $violation);
            self::assertSame('venue.error.unknown_town', $violation->getMessage());
        }

        self::assertSame(0, $this->rows('venues'));
        self::assertSame(0, $this->rows('portal_venue_access'));
        self::assertSame(0, $this->rows('portal_submissions'));
        self::assertSame(0, $this->rows('admin_audit_log'));
    }

    public function test_nine_unfinished_allow_one_more(): void
    {
        $manager = PortalUserFactory::createOne();
        $this->unfinished($manager, 9);

        $this->create($manager);

        self::assertSame(10, $this->submissions()->countUnfinished($manager->id()));
    }

    public function test_ten_unfinished_are_refused_and_write_nothing(): void
    {
        $manager = PortalUserFactory::createOne();
        $this->unfinished($manager, 10);

        try {
            $this->create($manager);
            self::fail('The account is at its limit.');
        } catch (HandlerFailedException $failure) {
            $refusal = $failure->getPrevious();
            self::assertInstanceOf(TooManyUnfinishedLocations::class, $refusal);
            self::assertSame(CreateOwnLocation::MAX_UNFINISHED, $refusal->limit);
        }

        self::assertSame(10, $this->rows('venues'));
        self::assertSame(10, $this->rows('portal_venue_access'));
        self::assertSame(10, $this->rows('portal_submissions'));
        self::assertSame(0, $this->rows('admin_audit_log'));
    }

    public function test_submitted_counts_as_unfinished(): void
    {
        $manager = PortalUserFactory::createOne();
        $this->holds($manager, SubmissionFactory::new()->submitted());

        self::assertSame(1, $this->submissions()->countUnfinished($manager->id()));
    }

    public function test_rejected_counts_as_unfinished(): void
    {
        $manager = PortalUserFactory::createOne();
        $this->holds($manager, SubmissionFactory::new()->rejected());

        self::assertSame(1, $this->submissions()->countUnfinished($manager->id()));
    }

    public function test_published_accepted_offline_claimed_and_foreign_rows_do_not_count(): void
    {
        $manager = PortalUserFactory::createOne();

        $published = VenueFactory::createOne(['status' => VenueStatus::Published]);
        SubmissionFactory::createOne(['venue' => $published]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $published]);

        $this->holds($manager, SubmissionFactory::new()->accepted());

        $claimed = VenueFactory::createOne(['status' => VenueStatus::Unpublished]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $claimed]);

        $pending = VenueFactory::createOne(['status' => VenueStatus::Unpublished]);
        SubmissionFactory::createOne(['venue' => $pending]);
        PortalVenueAccessFactory::createOne(['user' => $manager, 'venue' => $pending]);

        $this->holds(PortalUserFactory::createOne(), SubmissionFactory::new());

        self::assertSame(0, $this->submissions()->countUnfinished($manager->id()));
    }

    public function test_deleting_a_location_frees_a_slot(): void
    {
        $manager = PortalUserFactory::createOne();
        $venues = $this->unfinished($manager, 10);
        $this->em->clear();

        $this->bus()->dispatch(new DeleteVenue($venues[0]->number(), $venues[0]->name()));

        $this->create($manager);
        self::assertSame(10, $this->submissions()->countUnfinished($manager->id()));
    }

    private function create(PortalUser $manager, string $town = self::TOWN): int
    {
        $number = $this->bus()->dispatch(new CreateOwnLocation($manager->id(), 'Seehaus Meilen', 120, $town))->last(HandledStamp::class)?->getResult();
        self::assertIsInt($number);

        return $number;
    }

    /**
     * @return list<Venue>
     */
    private function unfinished(PortalUser $manager, int $count): array
    {
        $venues = [];
        for ($i = 0; $i < $count; ++$i) {
            $venues[] = $this->holds($manager, SubmissionFactory::new());
        }

        return $venues;
    }

    private function holds(PortalUser $manager, SubmissionFactory $submission): Venue
    {
        $venue = VenueFactory::createOne(['status' => VenueStatus::Unpublished]);
        $submission->create(['venue' => $venue]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);

        return $venue;
    }

    private function rows(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function submissions(): SubmissionRepository
    {
        $submissions = self::getContainer()->get(SubmissionRepository::class);
        self::assertInstanceOf(SubmissionRepository::class, $submissions);

        return $submissions;
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }
}
