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
            'tier' => 'premium',
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
            'faq' => ['own_catering' => 'allowed', 'daylight' => null],
            'party' => ['allowed' => 'limited', 'has_curfew' => true, 'curfew_at' => '02:00'],
            'rating' => ['score' => 9.0, 'count' => 47, 'highlights' => ['food', 'atmosphere']],
            'terms' => [
                'setting' => ['lake'], 'building' => ['castle', 'event_venue'], 'suitable_for' => ['wedding'], 'surroundings' => ['garden'],
                'ceremony' => ['free_ceremony'], 'amenity' => ['overnight', 'dance_floor'], 'dining' => ['vegan'], 'vendor' => ['dj'], 'usage' => ['exclusive'], 'language' => ['de', 'gsw'],
            ],
            'main_terms' => ['building' => 'castle', 'setting' => 'lake'],
            'translations' => ['de' => [
                'summary' => 'Kurz.', 'description' => 'Lang.', 'meta_description' => 'Meta.', 'parking_text' => 'Parkplätze.',
                'ambience' => 'Romantisch am See', 'capacity_note' => 'Mit Tanzfläche 100 Plätze.', 'party_note' => 'Musik bis 2 Uhr.', 'opening_hours' => 'Mai bis Oktober',
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
            'reviews' => [['first_name' => 'Laura', 'role' => 'visitor_f', 'month' => '2025-06', 'locale' => 'de', 'text' => 'Schön.']],
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
        yield 'unknown tier' => [$set($full, 'tier', 'gold'), '/tier: "gold" is not an allowed value'];
        yield 'extra property' => [$set($full, 'slug', 'schloss'), '/: Unknown properties: slug'];
        yield 'unquoted zip' => [$set($full, 'address.zip', 8001), '/address/zip:'];
        yield 'month as int' => [$set($full, 'reviews.0.month', 202506), '/reviews/0/month:'];
        yield 'bad phone' => [$set($full, 'contact.phone', '044 123 45 67'), '/contact/phone:'];
        yield 'four highlights' => [$set($full, 'rating.highlights', ['food', 'atmosphere', 'value', 'location']), '/rating/highlights:'];
        yield 'missing de translation' => [$set($full, 'translations', ['fr' => ['summary' => 'Court.']]), '/translations:'];
        yield 'empty seating entry' => [$set($full, 'rooms.0.seating', ['concert' => []]), '/rooms/0/seating/concert:'];
        yield 'gif photo' => [$set($full, 'photos.0.file', 'a.gif'), '/photos/0/file:'];
        yield 'photo in a sub folder' => [$set($full, 'photos.0.file', 'sub/a.jpg'), '/photos/0/file:'];
        yield 'unknown price level' => [$set($full, 'price_level', 'cheap'), '/price_level: "cheap" is not an allowed value'];
        yield 'curfew at 24:00' => [$set($full, 'party.curfew_at', '24:00'), '/party/curfew_at:'];
        yield 'curfew without a leading zero' => [$set($full, 'party.curfew_at', '2:00'), '/party/curfew_at:'];
        yield 'instagram over http' => [$set($full, 'contact.instagram', 'http://www.instagram.com/beispiel'), '/contact/instagram:'];
        yield 'seated capacity zero' => [$set($full, 'capacity.seated', 0), '/capacity/seated:'];
        yield 'unknown party value' => [$set($full, 'party.allowed', 'maybe'), '/party/allowed: "maybe" is not an allowed value'];
        yield 'ambience of 61 characters' => [$set($full, 'translations.de.ambience', str_repeat('a', 61)), '/translations/de/ambience:'];
        yield 'empty opening hours' => [$set($full, 'translations.de.opening_hours', ''), '/translations/de/opening_hours:'];
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
        $data['tier'] = 'gold';
        $data['address']['zip'] = '80';
        $data['geo']['lat'] = 1.0;

        self::assertGreaterThanOrEqual(3, \count(self::schema()->validate(self::object($data))));
    }

    public function test_a_venue_needs_only_the_required_fields(): void
    {
        $errors = self::schema()->validate(self::object([
            'name' => 'Gasthaus Beispiel',
            'tier' => 'basic',
            'capacity' => ['max' => 80],
            'translations' => ['de' => ['summary' => 'Kurz.']],
        ]));

        self::assertSame([], $errors);
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
