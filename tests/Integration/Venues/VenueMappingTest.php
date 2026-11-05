<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Geo\Entity\Canton;
use App\Geo\Entity\Town;
use App\Media\ValueObject\GeneratedImage;
use App\Media\ValueObject\ImageFormat;
use App\Media\ValueObject\ImageVariants;
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
use App\Venues\ValueObject\PartyAllowed;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\RatingHighlight;
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
        $venue->summariseRooms(1, 180, '4.20');
        $venue->changePriceLevel(PriceLevel::Exclusive);
        $venue->changeSeatingCapacity(120, 180);
        $venue->describePartyRules(PartyAllowed::Limited, true, new \DateTimeImmutable('1970-01-01 02:30:00'));
        $venue->changeInstagram('https://www.instagram.com/testhaus.vier');

        $translation = new VenueTranslation($venue, 'de', 'testhaus-vier-mapping', 'Kurz.', 'Lang.', 'Meta.', 'Parkplätze vor dem Haus.');
        $translation->describeWedding('Romantisch am See', 'Mit Bühne 100 Plätze.', 'Musik bis 2 Uhr.', 'Mai bis Oktober');

        $room = new Room($venue, 1, 10, 120, 180, '4.20', FloorMaterial::Parquet);
        $room->offerSeating(Seating::Banquet, 20, 100);
        $document = new VenueDocument($venue, 1, 'demo/testhaus-vier/1.pdf', 2048);
        $photo = new VenuePhoto($venue, $room, 1, 'demo/testhaus-vier/saal.jpg', new GeneratedImage(2400, 1600, '#8a7f70', new ImageVariants([360, 640, 960, 1280, 1920], [ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg])));
        $building = $this->em->find(TaxonomyTerm::class, ['termGroup' => TermGroup::Building, 'code' => 'villa']);
        $setting = $this->em->find(TaxonomyTerm::class, ['termGroup' => TermGroup::Setting, 'code' => 'lake']);
        self::assertInstanceOf(TaxonomyTerm::class, $building);
        self::assertInstanceOf(TaxonomyTerm::class, $setting);

        foreach ([
            $translation,
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
        self::assertSame(1.5, $loaded->distanceStationKm());
        self::assertSame(4.2, $loaded->maxRoomHeightM());
        self::assertSame('zh', $loaded->canton()?->code());
        self::assertSame(PriceLevel::Exclusive, $loaded->priceLevel());
        self::assertSame(120, $loaded->capacitySeated());
        self::assertSame(180, $loaded->capacityStanding());
        self::assertSame(PartyAllowed::Limited, $loaded->party());
        self::assertTrue($loaded->hasCurfew());
        self::assertSame('02:30', $loaded->curfewAt()?->format('H:i'));
        self::assertSame('https://www.instagram.com/testhaus.vier', $loaded->instagram());

        $loadedTranslation = $this->em->getRepository(VenueTranslation::class)->findOneBy(['venue' => $loaded]);
        self::assertInstanceOf(VenueTranslation::class, $loadedTranslation);
        self::assertSame('Romantisch am See', $loadedTranslation->ambience());
        self::assertSame('Mit Bühne 100 Plätze.', $loadedTranslation->capacityNote());
        self::assertSame('Musik bis 2 Uhr.', $loadedTranslation->partyNote());
        self::assertSame('Mai bis Oktober', $loadedTranslation->openingHours());

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
        self::assertSame([2400, 1600, '#8a7f70'], [$loadedPhoto->width(), $loadedPhoto->height(), $loadedPhoto->dominantColor()]);
        self::assertSame(['widths' => [360, 640, 960, 1280, 1920], 'formats' => ['avif', 'webp', 'jpg']], $loadedPhoto->variants()->toArray());

        $loadedPhoto->recordVariants(new GeneratedImage(300, 200, '#000000', new ImageVariants([300], [ImageFormat::Webp, ImageFormat::Jpeg])));
        $this->em->flush();
        $this->em->clear();

        $regenerated = $this->em->find(VenuePhoto::class, $photo->id());
        self::assertInstanceOf(VenuePhoto::class, $regenerated);
        self::assertSame([300, 200, '#000000'], [$regenerated->width(), $regenerated->height(), $regenerated->dominantColor()]);
        self::assertSame(['widths' => [300], 'formats' => ['webp', 'jpg']], $regenerated->variants()->toArray());
    }

    public function test_a_venue_without_a_location_round_trips(): void
    {
        $canton = $this->em->find(Canton::class, 'be');
        self::assertInstanceOf(Canton::class, $canton);
        $cantonOnly = new Venue('Testhaus Kanton', Tier::Basic, VenueStatus::Published, 80, null, null, null, null, null, null, null, $canton);
        $nothing = new Venue('Testhaus Nichts', Tier::Basic, VenueStatus::Published, 80, null, null, null, null, null, null);
        $translation = new VenueTranslation($nothing, 'de', 'testhaus-nichts', 'Kurz.');
        $translation->changeRegion('Schweizweit');
        foreach ([$cantonOnly, $nothing, $translation] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();

        $loadedCantonOnly = $this->em->find(Venue::class, $cantonOnly->id());
        self::assertInstanceOf(Venue::class, $loadedCantonOnly);
        self::assertSame('be', $loadedCantonOnly->canton()?->code());
        self::assertNull($loadedCantonOnly->town());
        self::assertNull($loadedCantonOnly->zip());
        self::assertNull($loadedCantonOnly->lat());
        self::assertNull($loadedCantonOnly->lng());

        $loadedNothing = $this->em->find(Venue::class, $nothing->id());
        self::assertInstanceOf(Venue::class, $loadedNothing);
        self::assertNull($loadedNothing->canton());
        $loadedTranslation = $this->em->getRepository(VenueTranslation::class)->findOneBy(['venue' => $loadedNothing]);
        self::assertInstanceOf(VenueTranslation::class, $loadedTranslation);
        self::assertSame('Schweizweit', $loadedTranslation->region());
    }

    public function test_the_google_place_round_trips(): void
    {
        $placed = $this->venue('Testhaus Google');
        $placed->changeGooglePlace('ChIJN1t_tDeuEmsRUsoyG83frY4', true);
        $plain = $this->venue('Testhaus Ohne');
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->find(Venue::class, $placed->id());
        self::assertInstanceOf(Venue::class, $loaded);
        self::assertSame('ChIJN1t_tDeuEmsRUsoyG83frY4', $loaded->googlePlaceId());
        self::assertTrue($loaded->googleRatingHidden());

        $loadedPlain = $this->em->find(Venue::class, $plain->id());
        self::assertInstanceOf(Venue::class, $loadedPlain);
        self::assertNull($loadedPlain->googlePlaceId());
        self::assertFalse($loadedPlain->googleRatingHidden());
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
