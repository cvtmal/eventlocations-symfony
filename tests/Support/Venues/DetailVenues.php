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
use App\Factory\VenueRatingHighlightFactory;
use App\Factory\VenueReviewFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Town;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\RatingHighlight;
use App\Venues\ValueObject\ReviewerRole;
use App\Venues\ValueObject\Seating;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\ORM\EntityManagerInterface;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * The venues of the detail-page tests (#8), built with the Foundry factories: a "full" venue
 * with every section of DP-2 to DP-16 and a "minimal" one with only the required fields.
 * Callers run inside a rolled-back transaction and use the Factories trait.
 */
final class DetailVenues
{
    public const FULL_SLUG = 'schloss-seeblick';
    public const FULL_PHONE = '+41441234567';
    public const MINIMAL_SLUG = 'gartenhaus-minimal';

    public static function full(EntityManagerInterface $em, string $slug = self::FULL_SLUG, string $name = 'Schloss Seeblick'): Venue
    {
        $venue = VenueFactory::createOne([
            'name' => $name,
            'tier' => Tier::Premium,
            'status' => VenueStatus::Published,
            'town' => self::town('Zürich'),
            'capacityMin' => 50,
            'capacityMax' => 300,
            'street' => 'Seestrasse 1',
        ]);
        $venue->changeContact(self::FULL_PHONE, 'info@seeblick.example', 'https://seeblick.example', 'Anna Muster');
        $venue->changeDistances('0.9', 'Zürich Stadelhofen', '3.2', null, '12.0', 'Zürich');
        $venue->answerFaq(OwnCatering::Allowed, true);
        $venue->rate('9.2', 474);
        $em->flush();

        VenueTranslationFactory::createOne([
            'venue' => $venue,
            'slug' => $slug,
            'summary' => 'Ein Schloss am See.',
            'description' => "Erster Absatz über das Schloss.\n\nZweiter Absatz mit dem Park.",
            'metaDescription' => 'Schloss Seeblick: Hochzeit am See.',
            'parkingText' => '100 Parkplätze (kostenlos)',
        ]);

        VenueTermFactory::new()->term(TermGroup::Building, 'castle')->main()->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Building, 'villa')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Setting, 'lake')->main()->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::SuitableFor, 'family_celebration')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::SuitableFor, 'wedding')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Infrastructure, 'stage')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Infrastructure, 'wifi')->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Surroundings, 'garden')->create(['venue' => $venue]);

        foreach ([RatingHighlight::Food, RatingHighlight::Atmosphere, RatingHighlight::Location] as $i => $highlight) {
            VenueRatingHighlightFactory::createOne(['venue' => $venue, 'position' => $i + 1, 'code' => $highlight]);
        }

        foreach (['Preisliste', 'Menüvorschläge'] as $i => $title) {
            $document = VenueDocumentFactory::createOne(['venue' => $venue, 'position' => $i + 1]);
            VenueDocumentTranslationFactory::createOne(['document' => $document, 'title' => $title]);
        }

        $reviews = [
            ['Loreana', ReviewerRole::VisitorF, '2025-04-01', 'Ein wunderbarer Tag am See.'],
            ['Marco', ReviewerRole::OrganiserM, '2025-01-01', 'Alles hat geklappt.'],
            ['Sandra', ReviewerRole::OrganiserF, '2024-06-01', 'Sehr freundliche Gastgeber.'],
            ['Peter', ReviewerRole::VisitorM, '2023-03-01', 'Schöne Aussicht.'],
        ];
        foreach ($reviews as [$firstName, $role, $month, $text]) {
            VenueReviewFactory::createOne(['venue' => $venue, 'firstName' => $firstName, 'role' => $role, 'reviewedMonth' => new \DateTimeImmutable($month), 'text' => $text]);
        }

        $hall = RoomFactory::createOne(['venue' => $venue, 'position' => 1, 'capacityMin' => 50, 'capacityMax' => 300, 'areaM2' => 220, 'heightM' => '4.00', 'floorMaterial' => FloorMaterial::Parquet]);
        $hall->offerSeating(Seating::Banquet, 50, 180);
        $hall->offerSeating(Seating::Seminar, null, 120);
        $hall->offerSeating(Seating::Concert, 60, 250);
        $hall->offerSeating(Seating::Apero, 100, 300);
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

    public static function minimal(string $slug = self::MINIMAL_SLUG, string $name = 'Gartenhaus Minimal'): Venue
    {
        $venue = VenueFactory::createOne([
            'name' => $name,
            'tier' => Tier::Basic,
            'status' => VenueStatus::Published,
            'town' => self::town('Bern'),
            'capacityMin' => null,
            'capacityMax' => 80,
            'street' => null,
        ]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug, 'summary' => 'Ein kleines Gartenhaus.']);

        return $venue;
    }

    public static function town(string $name): Town
    {
        $town = repository(Town::class)->findOneBy(['name' => $name]);
        \assert($town instanceof Town);

        return $town;
    }
}
