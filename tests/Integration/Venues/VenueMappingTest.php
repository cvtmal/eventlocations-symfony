<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Geo\Entity\Town;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Entity\Room;
use App\Venues\Entity\RoomTranslation;
use App\Venues\Entity\TaxonomyTerm;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueDocument;
use App\Venues\Entity\VenueDocumentTranslation;
use App\Venues\Entity\VenuePhoto;
use App\Venues\Entity\VenuePhotoTranslation;
use App\Venues\Entity\VenueRatingHighlight;
use App\Venues\Entity\VenueReview;
use App\Venues\Entity\VenueSlugHistory;
use App\Venues\Entity\VenueTerm;
use App\Venues\Entity\VenueTranslation;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\RatingHighlight;
use App\Venues\ValueObject\RatingLabel;
use App\Venues\ValueObject\ReviewerRole;
use App\Venues\ValueObject\Seating;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Venues mapping against the real schema: the values the database generates are read
 * back, and the composite term key round-trips. Each test runs in a rolled-back transaction.
 */
final class VenueMappingTest extends KernelTestCase
{
    use ImportsTownDataset;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    public function test_number_is_assigned_by_the_database_on_flush(): void
    {
        $first = $this->venue('Testhaus Eins');
        $second = $this->venue('Testhaus Zwei');
        $this->em->flush();

        self::assertGreaterThan(0, $first->number());
        self::assertGreaterThan($first->number(), $second->number());
    }

    public function test_venue_term_round_trips_its_taxonomy_term(): void
    {
        $venue = $this->venue('Testhaus Drei');
        $castle = $this->em->find(TaxonomyTerm::class, ['termGroup' => TermGroup::Building, 'code' => 'castle']);
        self::assertInstanceOf(TaxonomyTerm::class, $castle);
        $this->em->persist(new VenueTerm($venue, $castle, true));
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->getRepository(VenueTerm::class)->findOneBy(['venue' => $venue->id()]);
        self::assertInstanceOf(VenueTerm::class, $loaded);
        self::assertSame(TermGroup::Building, $loaded->term()->termGroup());
        self::assertSame('castle', $loaded->term()->code());
        self::assertSame(7, $loaded->term()->primaryPosition());
        self::assertTrue($loaded->isMain());
    }

    public function test_full_venue_graph_round_trips(): void
    {
        $venue = $this->venue('Testhaus Vier');
        $venue->changeTier(Tier::TopPremium);
        $venue->changeContact('+41441234567', 'info@example.ch', 'https://example.ch', 'Frau Muster');
        $venue->changeDistances('1.5', 'Stäfa', '12.0', 'A3', null, null);
        $venue->answerFaq(OwnCatering::FixedCaterer, true);
        $venue->rate('9.1', 42);
        $venue->summariseRooms(1, 180, '4.20');

        $room = new Room($venue, 1, 10, 120, 180, '4.20', FloorMaterial::Parquet);
        $room->offerSeating(Seating::Banquet, 20, 100);
        $document = new VenueDocument($venue, 1, 'demo/testhaus-vier/1.pdf', 2048);
        $photo = new VenuePhoto($venue, $room, 1, 'demo/testhaus-vier/saal.jpg');
        $building = $this->em->find(TaxonomyTerm::class, ['termGroup' => TermGroup::Building, 'code' => 'villa']);
        $setting = $this->em->find(TaxonomyTerm::class, ['termGroup' => TermGroup::Setting, 'code' => 'lake']);
        self::assertInstanceOf(TaxonomyTerm::class, $building);
        self::assertInstanceOf(TaxonomyTerm::class, $setting);

        foreach ([
            new VenueTranslation($venue, 'de', 'testhaus-vier-mapping', 'Kurz.', 'Lang.', 'Meta.', 'Parkplätze vor dem Haus.'),
            new VenueSlugHistory($venue, 'de', 'testhaus-4-mapping'),
            new VenueTerm($venue, $building, true),
            new VenueTerm($venue, $setting, true),
            new VenueRatingHighlight($venue, 1, RatingHighlight::Location),
            new VenueReview($venue, 'de', 'Anna', ReviewerRole::OrganiserF, new \DateTimeImmutable('2026-03-15'), 'Sehr schön.'),
            $room,
            new RoomTranslation($room, 'de', 'Saal'),
            $document,
            new VenueDocumentTranslation($document, 'de', 'Preisliste'),
            $photo,
            new VenuePhotoTranslation($photo, 'de', 'Der Saal'),
        ] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        self::assertSame(3, $venue->tierRank());
        $this->em->clear();

        $loaded = $this->em->find(Venue::class, $venue->id());
        self::assertInstanceOf(Venue::class, $loaded);
        self::assertSame($venue->number(), $loaded->number());
        self::assertSame(Tier::TopPremium, $loaded->tier());
        self::assertSame(3, $loaded->tierRank());
        self::assertSame(VenueStatus::Published, $loaded->status());
        self::assertSame(OwnCatering::FixedCaterer, $loaded->ownCatering());
        self::assertTrue($loaded->daylight());
        self::assertSame(9.1, $loaded->ratingScore());
        self::assertSame(RatingLabel::Outstanding, $loaded->ratingLabel());
        self::assertSame(1.5, $loaded->distanceStationKm());
        self::assertSame(4.2, $loaded->maxRoomHeightM());
        self::assertSame('zh', $loaded->canton()->code());

        $terms = $this->em->getRepository(VenueTerm::class)->findBy(['venue' => $loaded]);
        self::assertCount(2, $terms);
        foreach ($terms as $term) {
            self::assertTrue($term->isMain());
            self::assertSame($term->termGroup(), $term->term()->termGroup());
            self::assertSame($term->termCode(), $term->term()->code());
        }

        $loadedRoom = $this->em->getRepository(Room::class)->findOneBy(['venue' => $loaded]);
        self::assertInstanceOf(Room::class, $loadedRoom);
        self::assertSame(FloorMaterial::Parquet, $loadedRoom->floorMaterial());
        self::assertSame(100, $loadedRoom->seatingMax(Seating::Banquet));

        $review = $this->em->getRepository(VenueReview::class)->findOneBy(['venue' => $loaded]);
        self::assertInstanceOf(VenueReview::class, $review);
        self::assertSame(ReviewerRole::OrganiserF, $review->role());
        self::assertSame('2026-03-01', $review->reviewedMonth()->format('Y-m-d'));

        $highlight = $this->em->getRepository(VenueRatingHighlight::class)->findOneBy(['venue' => $loaded]);
        self::assertInstanceOf(VenueRatingHighlight::class, $highlight);
        self::assertSame(RatingHighlight::Location, $highlight->code());

        $loadedPhoto = $this->em->getRepository(VenuePhoto::class)->findOneBy(['venue' => $loaded]);
        self::assertInstanceOf(VenuePhoto::class, $loadedPhoto);
        self::assertSame($room->id(), $loadedPhoto->room()?->id());
    }

    private function venue(string $name): Venue
    {
        $town = $this->em->getRepository(Town::class)->findOneBy(['name' => 'Stäfa']);
        self::assertInstanceOf(Town::class, $town);
        $venue = new Venue($name, Tier::Premium, VenueStatus::Published, 120, 20, $town, $town->zip(), 'Seestrasse 1', $town->lat(), $town->lng());
        $this->em->persist($venue);

        return $venue;
    }
}
