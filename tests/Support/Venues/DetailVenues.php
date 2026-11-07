<?php

declare(strict_types=1);

namespace App\Tests\Support\Venues;

use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenueDocumentFactory;
use App\Factory\VenueDocumentTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenuePhotoTranslationFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Canton;
use App\Geo\Entity\Town;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\Seating;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\ORM\EntityManagerInterface;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * The venues of the detail-page tests (#8), built with the Foundry factories: a "full" venue
 * with every section of DP-2 to DP-17 and a "minimal" one with only the required fields.
 * The full venue is on the Pro tier, so every section shows (the Free limits would cut it, F12); the
 * minimal one and the place-less ones are on Free, the default of a new venue.
 * Callers run inside a rolled-back transaction and use the Factories trait.
 *
 * Some terms are inserted out of position order on purpose, so a test sees the read model sort
 * them. The full venue has no technology term: other suites rely on that group being empty.
 */
final class DetailVenues
{
    public const FULL_SLUG = 'schloss-seeblick';
    public const FULL_PHONE = '+41441234567';
    public const MINIMAL_SLUG = 'gartenhaus-minimal';
    public const OVERFULL_SLUG = 'haus-ueberfluss';
    public const OVERFULL_ROOMS = ['Rittersaal', 'Gewölbekeller'];
    public const OVERFULL_DOCUMENTS = ['Preisliste', 'Menüvorschläge'];

    /** Gallery photos of overfull(): two more than Free shows. */
    public const OVERFULL_GALLERY = 8;

    public static function full(EntityManagerInterface $em, string $slug = self::FULL_SLUG, string $name = 'Schloss Seeblick'): Venue
    {
        $venue = VenueFactory::createOne([
            'name' => $name,
            'tier' => KnownTiers::PRO,
            'status' => VenueStatus::Published,
            'town' => self::town('Zürich'),
            'capacityMin' => 50,
            'capacityMax' => 300,
            'street' => 'Seestrasse 1',
        ]);
        $venue->changeContact(self::FULL_PHONE, 'info@seeblick.example', 'https://seeblick.example', 'Anna Muster');
        $venue->changeDistances('0.9', 'Zürich Stadelhofen', '3.2', null, '12.0', 'Zürich');
        $venue->answerFaq(OwnCatering::Allowed, true);
        $venue->changePriceLevel(PriceLevel::Medium);
        $venue->changeSeatingCapacity(1200, 1500);
        $venue->changeInstagram('https://www.instagram.com/schloss.seeblick');
        $em->flush();

        $translation = VenueTranslationFactory::createOne([
            'venue' => $venue,
            'slug' => $slug,
            'summary' => 'Ein Schloss am See.',
            'description' => "Erster Absatz über das Schloss.\n\nZweiter Absatz mit dem Park.",
            'metaDescription' => 'Schloss Seeblick: Hochzeit am See.',
            'parkingText' => '100 Parkplätze (kostenlos)',
        ]);
        $translation->describeEvent('Romantisch am Wasser', 'Mit Tanzfläche 900 Sitzplätze.', 'Mai bis Oktober, täglich ab 10 Uhr');
        $em->flush();

        VenueTermFactory::new()->term(TermGroup::Building, 'castle')->main()->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Building, 'villa')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Setting, 'lake')->main()->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::SuitableFor, 'wedding')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::SuitableFor, 'family_celebration')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::SuitableFor, 'corporate_event')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Infrastructure, 'cloakroom')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Infrastructure, 'air_conditioning')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Surroundings, 'garden')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Amenity, 'dance_floor')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Catering, 'gluten_free')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Catering, 'vegetarian')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Language, 'de')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Language, 'en')->create(['venue' => $venue]);

        foreach (['Preisliste', 'Menüvorschläge'] as $i => $title) {
            $document = VenueDocumentFactory::createOne(['venue' => $venue, 'position' => $i + 1]);
            VenueDocumentTranslationFactory::createOne(['document' => $document, 'title' => $title]);
        }

        $hall = RoomFactory::createOne(['venue' => $venue, 'position' => 1, 'capacityMin' => 50, 'capacityMax' => 300, 'areaM2' => 220, 'heightM' => '4.00', 'floorMaterial' => FloorMaterial::Parquet]);
        $hall->offerSeating(Seating::Banquet, 50, 180);
        $hall->offerSeating(Seating::Seminar, null, 120);
        $hall->offerSeating(Seating::Concert, 60, 250);
        $hall->offerSeating(Seating::Apero, 100, 300);
        $hall->offerSeating(Seating::UShape, null, 40);
        $hall->offerSeating(Seating::Block, 60, 80);
        $em->flush();
        RoomTranslationFactory::createOne(['room' => $hall, 'name' => 'Rittersaal', 'description' => 'Der grosse Saal mit Kronleuchtern.']);
        $cellar = RoomFactory::createOne(['venue' => $venue, 'position' => 2, 'capacityMin' => null, 'capacityMax' => null, 'areaM2' => null, 'heightM' => null, 'floorMaterial' => null]);
        RoomTranslationFactory::createOne(['room' => $cellar, 'name' => 'Gewölbekeller']);

        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $hall, 'position' => 2]);
        $hallPhoto = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $hall, 'position' => 1]);
        VenuePhotoTranslationFactory::createOne(['photo' => $hallPhoto, 'alt' => 'Der Rittersaal']);
        foreach ([1, 2, 3] as $position) {
            $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
            if ($position < 3) {
                VenuePhotoTranslationFactory::createOne(['photo' => $photo, 'alt' => 'Aussicht '.$position]);
            }
        }

        return $venue;
    }

    /**
     * @param string $tier a tier code (`KnownTiers`); Free unless a test needs what Pro shows
     */
    public static function minimal(string $slug = self::MINIMAL_SLUG, string $name = 'Gartenhaus Minimal', string $tier = KnownTiers::FREE): Venue
    {
        $venue = VenueFactory::createOne([
            'name' => $name,
            'tier' => $tier,
            'status' => VenueStatus::Published,
            'town' => self::town('Bern'),
            'capacityMin' => null,
            'capacityMax' => 80,
            'street' => null,
        ]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug, 'summary' => 'Ein kleines Gartenhaus.']);

        return $venue;
    }

    /**
     * A published venue on the given tier that holds more than Free shows (F12): eight gallery photos (inserted from
     * the last position to the first, so only `position` puts them in order), two rooms (the first with a photo), two
     * PDFs, a phone, a website, an inquiry address, Instagram and a main building. What a page, a card or a feed shows
     * of it depends on the tier alone.
     *
     * @param string $tier a tier code (`KnownTiers`)
     */
    public static function overfull(string $tier, string $slug = self::OVERFULL_SLUG, string $name = 'Haus Überfluss'): Venue
    {
        $venue = VenueFactory::createOne([
            'name' => $name,
            'tier' => $tier,
            'status' => VenueStatus::Published,
            'town' => self::town('Bern'),
            'capacityMin' => null,
            'capacityMax' => 120,
            'street' => 'Aarestrasse 4',
        ]);
        $venue->changeContact(self::FULL_PHONE, 'anfrage@ueberfluss.example', 'https://ueberfluss.example', 'Beat Muster');
        $venue->changeInstagram('https://www.instagram.com/haus.ueberfluss');
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug, 'summary' => 'Ein Haus mit mehr, als der kleine Plan zeigt.']);
        VenueTermFactory::new()->term(TermGroup::Building, 'castle')->main()->create(['venue' => $venue]);

        foreach (self::OVERFULL_DOCUMENTS as $i => $title) {
            VenueDocumentTranslationFactory::createOne([
                'document' => VenueDocumentFactory::createOne(['venue' => $venue, 'position' => $i + 1]),
                'title' => $title,
            ]);
        }
        $rooms = [];
        foreach (self::OVERFULL_ROOMS as $i => $roomName) {
            $rooms[$i] = RoomFactory::createOne(['venue' => $venue, 'position' => $i + 1, 'capacityMin' => null, 'capacityMax' => 80, 'areaM2' => 100, 'heightM' => '3.50', 'floorMaterial' => null]);
            RoomTranslationFactory::createOne(['room' => $rooms[$i], 'name' => $roomName]);
        }
        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $rooms[0], 'position' => 1]);
        for ($position = self::OVERFULL_GALLERY; $position >= 1; --$position) {
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
        }

        return $venue;
    }

    /** A published venue with no place at all (#21). */
    public static function withoutPlace(string $slug = 'wiesenhof-ohne-ort', string $name = 'Wiesenhof Ohne Ort'): Venue
    {
        return self::locationless($slug, $name, null);
    }

    /** A published venue that knows only its canton, as most of the owner's data does (#21). */
    public static function inCantonOnly(string $slug = 'scheune-nur-kanton', string $name = 'Scheune Nur Kanton', string $cantonCode = 'zh'): Venue
    {
        $canton = repository(Canton::class)->find($cantonCode);
        \assert($canton instanceof Canton);

        return self::locationless($slug, $name, $canton);
    }

    /** A published venue with a canton and a pin but no town, as production's after the go-live import. */
    public static function inCantonWithPin(string $lat, string $lng, string $slug = 'scheune-mit-pin', string $name = 'Scheune Mit Pin', string $cantonCode = 'zh'): Venue
    {
        $canton = repository(Canton::class)->find($cantonCode);
        \assert($canton instanceof Canton);

        return self::locationless($slug, $name, $canton, null, ['lat' => $lat, 'lng' => $lng]);
    }

    /** A published venue that knows only a region text (#21). */
    public static function inRegionOnly(EntityManagerInterface $em, string $region, string $slug = 'remise-nur-region', string $name = 'Remise Nur Region'): Venue
    {
        $venue = self::locationless($slug, $name, null, $region);
        $em->flush();

        return $venue;
    }

    public static function town(string $name): Town
    {
        $town = repository(Town::class)->findOneBy(['name' => $name]);
        \assert($town instanceof Town);

        return $town;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function locationless(string $slug, string $name, ?Canton $canton, ?string $region = null, array $attributes = []): Venue
    {
        $venue = VenueFactory::new()->withoutLocation($canton)->create($attributes + [
            'name' => $name,
            'tier' => KnownTiers::FREE,
            'status' => VenueStatus::Published,
            'capacityMin' => null,
            'capacityMax' => 80,
        ]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug, 'summary' => 'Ein Ort für die Hochzeit.'])->changeRegion($region);

        return $venue;
    }
}
