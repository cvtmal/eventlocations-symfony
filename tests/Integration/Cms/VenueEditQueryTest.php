<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Cms\ReadModel\VenueEdit;
use App\Cms\ReadModel\VenueEditQuery;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Factory\AdminUserFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTermFactory;
use App\Geo\ValueObject\Canton;
use App\Shared\Uid\EntityId;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Integration\Search\ListingQueryTest;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Command\PublishVenue;
use App\Venues\Entity\Venue;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\TierLabel;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

final class VenueEditQueryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private EntityManagerInterface $em;
    private Connection $connection;
    private VenueEditQuery $query;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->query = new VenueEditQuery($this->connection, new VenueHeaderQuery($this->connection, ListingQueryTest::enabledLocale()));
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_a_full_venue_maps_every_card(): void
    {
        $venue = DetailVenues::full($this->em);
        $this->connection->executeStatement('UPDATE venues SET lat = 47.366512, lng = 8.548023 WHERE id = ?', [$venue->id()]);

        $edit = $this->find($venue);

        self::assertSame($venue->number(), $edit->number());
        self::assertSame((string) $this->connection->fetchOne('SELECT EXTRACT(EPOCH FROM updated_at)::bigint FROM venues WHERE id = ?', [$venue->id()]), $edit->loadedAt);
        self::assertSame([50, 300], [$edit->capacityMin, $edit->capacityMax]);
        self::assertSame('8001 Zürich', $edit->town);
        self::assertSame('zh', $edit->cantonCode);
        self::assertSame(['47.366512', '8.548023'], [$edit->lat, $edit->lng]);
        self::assertFalse($edit->coordinatesFromTown);
        self::assertNull($edit->townCentreHint());
        self::assertSame(['0.9', 'Zürich Stadelhofen', '3.2', null, '12.0', 'Zürich'], [$edit->stationKm, $edit->stationName, $edit->highwayKm, $edit->highwayName, $edit->airportKm, $edit->airportName]);
        self::assertSame([DetailVenues::FULL_PHONE, 'info@seeblick.example', 'https://seeblick.example', 'Anna Muster', 'https://www.instagram.com/schloss.seeblick'], [$edit->phone, $edit->inquiryEmail, $edit->website, $edit->contactPerson, $edit->instagram]);
        self::assertTrue($edit->daylight);
        self::assertSame([PriceLevel::Medium, 1200, 1500], [$edit->priceLevel, $edit->capacitySeated, $edit->capacityStanding]);
        self::assertSame(3, $edit->galleryPhotoCount);
        self::assertSame([null, false], [$edit->googlePlaceId, $edit->googleRatingHidden]);

        $de = $edit->translation('de');
        self::assertNotNull($de);
        self::assertSame(DetailVenues::FULL_SLUG, $de->slug);
        self::assertSame('Ein Schloss am See.', $de->summary);
        self::assertSame("Erster Absatz über das Schloss.\n\nZweiter Absatz mit dem Park.", $de->description);
        self::assertSame(['Schloss Seeblick: Hochzeit am See.', '100 Parkplätze (kostenlos)', null], [$de->metaDescription, $de->parkingText, $de->region]);
        self::assertSame(['Romantisch am Wasser', 'Mit Tanzfläche 900 Sitzplätze.', 'Mai bis Oktober, täglich ab 10 Uhr'], [$de->ambience, $de->capacityNote, $de->openingHours]);
        self::assertNull($edit->translation('fr'));

        self::assertSame(array_map(static fn (TermGroup $group): string => $group->value, TermGroup::cases()), array_keys($edit->terms));
        self::assertSame(['castle', 'villa'], $edit->terms['building']);
        self::assertSame(['lake'], $edit->terms['setting']);
        self::assertSame(['de', 'en'], $edit->terms['language']);
        self::assertSame([], $edit->terms['technology']);
        self::assertSame(['building' => 'castle', 'setting' => 'lake'], $edit->mainTerms);
        self::assertSame([], $edit->missingForPublish('de'));

        $header = $edit->header;
        self::assertSame(['Schloss Seeblick', VenueStatus::Published, true], [$header->name, $header->status, $header->isPublished]);
        self::assertEquals(new TierLabel(KnownTiers::PRO, 'Pro', true), $header->tier);
        self::assertSame(['Zürich', Canton::ZH, null], [$header->place->townName, $header->place->canton, $header->place->region]);
        self::assertSame(['Seestrasse 1', '8001', DetailVenues::FULL_SLUG, null, null], [$header->street, $header->zip, $header->slug, $header->contentKey, $header->cmsEditedAt]);
        self::assertNotNull($header->cover);
        $firstGalleryPhoto = $this->connection->fetchOne('SELECT id FROM venue_photos WHERE venue_id = ? AND room_id IS NULL ORDER BY position LIMIT 1', [$venue->id()]);
        self::assertSame($firstGalleryPhoto, $header->cover->photoId);
        self::assertSame('', $header->cover->alt);
        self::assertNull($header->lastEditor);
    }

    public function test_the_google_place_and_its_switch_are_read(): void
    {
        $venue = DetailVenues::minimal();
        $this->connection->executeStatement("UPDATE venues SET google_place_id = 'ChIJN1t_tDeuEmsRUsoyG83frY4', google_rating_hidden = true WHERE id = ?", [$venue->id()]);

        $edit = $this->find($venue);

        self::assertSame(['ChIJN1t_tDeuEmsRUsoyG83frY4', true], [$edit->googlePlaceId, $edit->googleRatingHidden]);
    }

    public function test_a_minimal_venue_has_nulls_and_the_publish_gaps(): void
    {
        $venue = DetailVenues::minimal();

        $edit = $this->find($venue);

        self::assertSame('3004 Bern', $edit->town);
        self::assertSame('be', $edit->cantonCode);
        self::assertSame([null, null, null], [$edit->capacityMin, $edit->stationKm, $edit->stationName]);
        self::assertSame([null, null], [$edit->phone, $edit->daylight]);
        self::assertSame([null, null, null], [$edit->priceLevel, $edit->capacitySeated, $edit->capacityStanding]);
        self::assertSame([], $edit->mainTerms);
        self::assertSame([null, false], [$edit->googlePlaceId, $edit->googleRatingHidden]);
        self::assertSame(0, $edit->galleryPhotoCount);
        self::assertNull($edit->header->cover);
        self::assertSame([VenueEdit::MISSING_MAIN_BUILDING, VenueEdit::MISSING_PHOTO], $edit->missingForPublish('de'));
    }

    public function test_a_venue_without_place(): void
    {
        $edit = $this->find(DetailVenues::withoutPlace());

        self::assertSame([null, null], [$edit->town, $edit->cantonCode]);
        self::assertFalse($edit->header->place->isKnown());
        self::assertSame([null, null], [$edit->header->street, $edit->header->zip]);
    }

    public function test_a_venue_in_a_canton_only(): void
    {
        $edit = $this->find(DetailVenues::inCantonOnly(cantonCode: 'ti'));

        self::assertNull($edit->town);
        self::assertSame('ti', $edit->cantonCode);
        self::assertSame(Canton::TI, $edit->header->place->canton);
        self::assertNull($edit->header->place->townName);
    }

    public function test_a_venue_with_a_region_only(): void
    {
        $edit = $this->find(DetailVenues::inRegionOnly($this->em, 'Rheintal'));

        self::assertSame([null, null], [$edit->town, $edit->cantonCode]);
        self::assertSame('Rheintal', $edit->translation('de')?->region);
        self::assertSame('Rheintal', $edit->header->place->region);
    }

    public function test_town_centre_coordinates_name_the_town(): void
    {
        $venue = DetailVenues::minimal();
        $this->connection->executeStatement('UPDATE venues SET lat = 46.948, lng = 7.4474, coordinates_from_town = TRUE WHERE id = ?', [$venue->id()]);

        $edit = $this->find($venue);

        self::assertTrue($edit->coordinatesFromTown);
        self::assertSame('Bern', $edit->townCentreHint());
    }

    public function test_an_unknown_or_out_of_range_number_is_null(): void
    {
        self::assertNull($this->query->find(999_999_999));
        self::assertNull($this->query->find(0));
        self::assertNull($this->query->find(\PHP_INT_MAX));
    }

    public function test_an_unpublished_venue_is_found(): void
    {
        $venue = DetailVenues::minimal();
        $this->connection->executeStatement("UPDATE venues SET status = 'unpublished' WHERE id = ?", [$venue->id()]);

        $header = $this->find($venue)->header;

        self::assertSame(VenueStatus::Unpublished, $header->status);
        self::assertFalse($header->isPublished);
    }

    public function test_a_room_photo_is_no_gallery_photo(): void
    {
        $venue = DetailVenues::full($this->em);
        $this->connection->executeStatement('DELETE FROM venue_photos WHERE venue_id = ? AND room_id IS NULL', [$venue->id()]);

        $edit = $this->find($venue);

        self::assertSame(0, $edit->galleryPhotoCount);
        self::assertNull($edit->header->cover);
        self::assertSame([VenueEdit::MISSING_PHOTO], $edit->missingForPublish('de'));
    }

    public function test_a_blank_summary_counts_as_missing(): void
    {
        $venue = DetailVenues::minimal();
        $this->connection->executeStatement("UPDATE venue_translations SET summary = '   ' WHERE venue_id = ?", [$venue->id()]);

        self::assertContains(VenueEdit::MISSING_SUMMARY, $this->find($venue)->missingForPublish('de'));
    }

    public function test_the_checklist_agrees_with_publish_venue(): void
    {
        $cases = [
            'nothing missing' => DetailVenues::full($this->em),
            'two gaps' => DetailVenues::minimal(),
            'no summary' => DetailVenues::full($this->em, 'ohne-text', 'Ohne Text'),
            'room photo only' => DetailVenues::full($this->em, 'nur-raumfoto', 'Nur Raumfoto'),
            'only a photo' => DetailVenues::withoutPlace(),
        ];
        $this->connection->executeStatement("UPDATE venue_translations SET summary = '' WHERE venue_id = ?", [$cases['no summary']->id()]);
        $this->connection->executeStatement('DELETE FROM venue_photos WHERE venue_id = ? AND room_id IS NULL', [$cases['room photo only']->id()]);
        VenuePhotoFactory::createOne(['venue' => $cases['only a photo'], 'position' => 1]);
        VenueTermFactory::new()->term(TermGroup::Building, 'farm_barn')->create(['venue' => $cases['two gaps']]);
        $this->connection->executeStatement("UPDATE venues SET status = 'unpublished'");

        foreach ($cases as $label => $venue) {
            self::assertSame($this->publishGaps($venue), $this->find($venue)->missingForPublish('de'), $label);
        }
    }

    public function test_the_last_editor_is_the_newest_audit_row(): void
    {
        $venue = DetailVenues::minimal();
        $staff = AdminUserFactory::createOne(['displayName' => 'Andrea Keller']);
        $this->audit($venue, 'admin_user', $staff->id(), '2026-10-01 10:00:00+02');
        $this->audit($venue, 'console', null, '2026-10-02 10:00:00+02');
        $this->audit($venue, 'admin_user', $staff->id(), '2026-10-03 10:00:00+02');

        $editor = $this->find($venue)->header->lastEditor;

        self::assertNotNull($editor);
        self::assertSame('Andrea Keller', $editor->name);
        self::assertEquals(new \DateTimeImmutable('2026-10-03 10:00:00+02'), $editor->at);
    }

    public function test_a_console_row_has_no_editor_name(): void
    {
        $venue = DetailVenues::minimal();
        $this->audit($venue, 'console', null, '2026-10-02 10:00:00+02');

        $editor = $this->find($venue)->header->lastEditor;

        self::assertNotNull($editor);
        self::assertNull($editor->name);
    }

    private function find(Venue $venue): VenueEdit
    {
        $this->em->flush();
        $edit = $this->query->find($venue->number());
        self::assertNotNull($edit);

        return $edit;
    }

    /**
     * @return list<string>
     */
    private function publishGaps(Venue $venue): array
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $this->connection->executeStatement('SAVEPOINT publish_gaps');
        try {
            $bus->dispatch(new PublishVenue($venue->number()));

            return [];
        } catch (HandlerFailedException $failure) {
            return VenueRuleViolation::unwrap($failure)->missing;
        } finally {
            $this->connection->executeStatement('ROLLBACK TO SAVEPOINT publish_gaps');
            $this->em->clear();
        }
    }

    private function audit(Venue $venue, string $actorType, ?string $actorId, string $at): void
    {
        $this->connection->insert('admin_audit_log', [
            'id' => EntityId::generate(),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'command' => 'App\Venues\Command\ChangeVenueBasics',
            'subject_type' => 'venue',
            'subject_id' => (string) $venue->number(),
            'changes' => '{}',
            'created_at' => $at,
        ]);
    }
}
