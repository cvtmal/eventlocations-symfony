<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ContentSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/content-format.schema.json through ContentSchema: a complete document passes, and
 * each failure names its JSON pointer.
 */
final class ContentSchemaTest extends TestCase
{
    /**
     * Every field of the format, fictional values.
     *
     * @return array<string, mixed>
     */
    public static function full(): array
    {
        return [
            'name' => 'Schloss Beispiel',
            'status' => 'published',
            'price_level' => 'medium',
            'capacity' => ['min' => 20, 'max' => 180, 'seated' => 120, 'standing' => 180],
            'address' => ['street' => 'Seestrasse 1', 'zip' => '8001', 'town' => 'Zürich', 'canton' => 'zh'],
            'geo' => ['lat' => 47.37, 'lng' => 8.54],
            'distances' => [
                'station' => ['km' => 0.9, 'name' => 'Bahnhof Beispiel'],
                'highway' => ['km' => 3.2, 'name' => 'A1'],
                'airport' => ['km' => 12.0],
            ],
            'contact' => ['phone' => '+41441234567', 'inquiry_email' => 'anfragen@example.ch', 'website' => 'https://example.ch', 'contact_person' => 'Anna Muster', 'instagram' => 'https://www.instagram.com/schloss.beispiel'],
            'faq' => ['daylight' => null],
            'terms' => [
                'setting' => ['lake'], 'building' => ['castle', 'event_venue'], 'suitable_for' => ['corporate_event', 'seminar'], 'infrastructure' => ['air_conditioning'],
                'furniture' => ['standing_tables'], 'technology' => ['wifi', 'projector_screen'], 'surroundings' => ['garden'],
                'amenity' => ['overnight', 'dance_floor'], 'catering' => ['in_house', 'vegan'], 'language' => ['de', 'gsw'],
            ],
            'main_terms' => ['building' => 'castle', 'setting' => 'lake', 'suitable_for' => 'corporate_event'],
            'translations' => ['de' => [
                'summary' => 'Kurz.', 'description' => 'Lang.', 'meta_description' => 'Meta.', 'parking_text' => 'Parkplätze.',
                'ambience' => 'Festlich am See', 'capacity_note' => 'Mit Bühne 100 Plätze.', 'opening_hours' => 'Mai bis Oktober',
            ]],
            'photos' => [['file' => 'aussen.jpg', 'alt' => ['de' => 'Schloss am See']], ['file' => 'innen.PNG']],
            'rooms' => [[
                'key' => 'rittersaal',
                'translations' => ['de' => ['name' => 'Rittersaal', 'description' => 'Gross.']],
                'capacity' => ['min' => 20, 'max' => 120],
                'area_m2' => 180,
                'height_m' => 6.5,
                'floor_material' => 'parquet',
                'seating' => ['banquet' => ['min' => 20, 'max' => 100], 'apero' => ['max' => 150]],
                'photos' => [['file' => 'saal.webp']],
            ]],
            'documents' => [['file' => 'preisliste.pdf', 'title' => ['de' => 'Preisliste']]],
        ];
    }

    public function test_a_complete_document_is_valid(): void
    {
        self::assertSame([], self::schema()->validate(self::object(self::full())));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function failures(): iterable
    {
        $full = self::full();
        $set = static function (array $data, string $path, mixed $value): array {
            $ref = &$data;
            foreach (explode('.', $path) as $segment) {
                $ref = &$ref[$segment];
            }
            $ref = $value;

            return $data;
        };

        yield 'unknown term code' => [$set($full, 'terms.building', ['castle', 'palace']), '/terms/building/1: "palace" is not an allowed value'];
        yield 'a tier, the folder carries none since F12' => [$set($full, 'tier', 'pro'), '/: Unknown properties: tier'];
        yield 'extra property' => [$set($full, 'slug', 'schloss'), '/: Unknown properties: slug'];
        yield 'unquoted zip' => [$set($full, 'address.zip', 8001), '/address/zip:'];
        yield 'bad phone' => [$set($full, 'contact.phone', '044 123 45 67'), '/contact/phone:'];
        yield 'missing de translation' => [$set($full, 'translations', ['fr' => ['summary' => 'Court.']]), '/translations:'];
        yield 'empty seating entry' => [$set($full, 'rooms.0.seating', ['concert' => []]), '/rooms/0/seating/concert:'];
        yield 'gif photo' => [$set($full, 'photos.0.file', 'a.gif'), '/photos/0/file:'];
        yield 'photo in a sub folder' => [$set($full, 'photos.0.file', 'sub/a.jpg'), '/photos/0/file:'];
        yield 'unknown price level' => [$set($full, 'price_level', 'cheap'), '/price_level: "cheap" is not an allowed value'];
        yield 'instagram over http' => [$set($full, 'contact.instagram', 'http://www.instagram.com/beispiel'), '/contact/instagram:'];
        yield 'seated capacity zero' => [$set($full, 'capacity.seated', 0), '/capacity/seated:'];
        yield 'the party section, retired in F3' => [$set($full, 'party', ['allowed' => 'limited', 'has_curfew' => true, 'curfew_at' => '02:00']), '/: Unknown properties: party'];
        yield 'the party note, retired in F3' => [$set($full, 'translations.de.party_note', 'Musik bis 2 Uhr.'), '/translations/de: Unknown properties: party_note'];
        yield 'the ceremony group, retired in F3' => [$set($full, 'terms.ceremony', ['chapel']), '/terms: Unknown properties: ceremony'];
        yield 'a building code, retired in F3' => [$set($full, 'terms.building', ['castle', 'wedding_venue']), '/terms/building/1: "wedding_venue" is not an allowed value'];
        yield 'wifi is no infrastructure code since F3' => [$set($full, 'terms.infrastructure', ['wifi']), '/terms/infrastructure/0: "wifi" is not an allowed value'];
        yield 'a main event type that is not an event type' => [$set($full, 'main_terms.suitable_for', 'class_reunion'), '/main_terms/suitable_for: "class_reunion" is not an allowed value'];
        yield 'ambience of 61 characters' => [$set($full, 'translations.de.ambience', str_repeat('a', 61)), '/translations/de/ambience:'];
        yield 'empty opening hours' => [$set($full, 'translations.de.opening_hours', ''), '/translations/de/opening_hours:'];
        yield 'the FAQ own catering, retired in F9 (D33)' => [$set($full, 'faq.own_catering', 'allowed'), '/faq: Unknown properties: own_catering'];
        yield 'a rating, retired in #53' => [$set($full, 'rating', ['score' => 9.0, 'count' => 47]), '/: Unknown properties: rating'];
        yield 'reviews, retired in #53' => [$set($full, 'reviews', [['first_name' => 'Laura', 'role' => 'visitor_f', 'month' => '2025-06', 'text' => 'Schön.']]), '/: Unknown properties: reviews'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('failures')]
    public function test_a_failure_names_its_pointer(array $data, string $expected): void
    {
        $errors = self::schema()->validate(self::object($data));

        self::assertNotSame([], $errors);
        self::assertNotEmpty(array_filter($errors, static fn (string $error): bool => str_starts_with($error, $expected)), implode("\n", $errors));
    }

    public function test_one_failing_field_does_not_report_its_valid_siblings_as_unknown(): void
    {
        $data = self::full();
        $data['terms']['building'] = ['palace'];

        $errors = self::schema()->validate(self::object($data));

        self::assertSame(['/terms/building/0: "palace" is not an allowed value'], $errors);
    }

    public function test_several_failures_come_back_together(): void
    {
        $data = self::full();
        $data['price_level'] = 'cheap';
        $data['address']['zip'] = '80';
        $data['geo']['lat'] = 1.0;

        self::assertGreaterThanOrEqual(3, \count(self::schema()->validate(self::object($data))));
    }

    public function test_a_venue_needs_only_the_required_fields(): void
    {
        $errors = self::schema()->validate(self::object([
            'name' => 'Gasthaus Beispiel',
            'capacity' => ['max' => 80],
            'translations' => ['de' => ['summary' => 'Kurz.']],
        ]));

        self::assertSame([], $errors);
    }

    public function test_a_draft_may_come_without_texts(): void
    {
        $errors = self::schema()->validate(self::object([
            'name' => 'Gasthaus Beispiel',
            'status' => 'unpublished',
            'capacity' => ['max' => 80],
        ]));

        self::assertSame([], $errors);
    }

    public function test_a_published_or_status_less_venue_needs_its_texts(): void
    {
        foreach ([['status' => 'published'], []] as $status) {
            $errors = self::schema()->validate(self::object(['name' => 'Gasthaus Beispiel', 'capacity' => ['max' => 80]] + $status));

            self::assertNotSame([], $errors);
            self::assertStringContainsString('translations', implode("\n", $errors));
        }
    }

    public function test_a_draft_with_texts_still_needs_the_de_summary(): void
    {
        $errors = self::schema()->validate(self::object([
            'name' => 'Gasthaus Beispiel',
            'status' => 'unpublished',
            'capacity' => ['max' => 80],
            'translations' => ['de' => ['description' => 'Lang.']],
        ]));

        self::assertNotSame([], $errors);
    }

    public function test_geo_needs_both_coordinates(): void
    {
        $data = self::full();
        unset($data['geo']['lng']);

        self::assertNotSame([], self::schema()->validate(self::object($data)));
    }

    public function test_an_unknown_canton_is_rejected(): void
    {
        $data = self::full();
        $data['address'] = ['canton' => 'xx'];

        self::assertSame(['/address/canton: "xx" is not an allowed value'], self::schema()->validate(self::object($data)));
    }

    public static function schema(): ContentSchema
    {
        return new ContentSchema(\dirname(__DIR__, 3));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function object(array $data): \stdClass
    {
        $object = json_decode(json_encode($data, \JSON_THROW_ON_ERROR), flags: \JSON_THROW_ON_ERROR);
        \assert($object instanceof \stdClass);

        return $object;
    }
}
