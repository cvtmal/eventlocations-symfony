<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Venues\ValueObject\Tier;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The venue migration's constraints, proven against the real schema with raw SQL. Each
 * test seeds one town, two venues and one row in every child table inside a transaction
 * that is rolled back afterwards.
 *
 * test_every_constraint_has_a_rejection_case() lists every chk_/uk_/fk_ name of the 13
 * tables, so a new constraint without a case here fails.
 */
final class VenueSchemaMigrationTest extends KernelTestCase
{
    private const TABLES = [
        'venues', 'venue_translations', 'venue_slug_history', 'taxonomy_terms', 'venue_terms',
        'venue_rating_highlights', 'venue_reviews', 'rooms', 'room_translations', 'venue_documents',
        'venue_document_translations', 'venue_photos', 'venue_photo_translations',
    ];

    private const IDS = [
        '{town}' => '019a0000-0000-7000-8000-000000000001',
        '{venue}' => '019a0000-0000-7000-8000-000000000101',
        '{venue2}' => '019a0000-0000-7000-8000-000000000102',
        '{room}' => '019a0000-0000-7000-8000-000000000201',
        '{doc}' => '019a0000-0000-7000-8000-000000000301',
        '{photo}' => '019a0000-0000-7000-8000-000000000401',
        '{missing}' => '019a0000-0000-7000-8000-000000000999',
    ];

    /** The NOT NULL image variant columns of venue_photos (#5) with valid values. */
    private const PHOTO_COLUMNS = [
        '{photo-cols}' => 'width, height, dominant_color, variants',
        '{photo-vals}' => '1600, 1067, \'#b9a58c\', \'{"widths": [360, 640, 960, 1280], "formats": ["avif", "webp", "jpg"]}\'',
    ];

    private const SEED = [
        "INSERT INTO towns (id, canton_code, zip, name, slug, bfs_number, lat, lng, population) VALUES ('{town}', 'zh', '8999', 'Testdorf Venues', 'testdorf-venues', 9999, 47.3, 8.5, 100)",
        "INSERT INTO venues (id, name, tier, status, capacity_max, zip, town_id, canton_code, lat, lng) VALUES ('{venue}', 'Testhaus', 'premium', 'published', 100, '8999', '{town}', 'zh', 47.3, 8.5), ('{venue2}', 'Zweithaus', 'basic', 'published', 50, '8999', '{town}', 'zh', 47.3, 8.5)",
        "INSERT INTO venue_translations (id, venue_id, locale, slug, summary) VALUES (gen_random_uuid(), '{venue}', 'de', 'testhaus-venues', 'Kurz.'), (gen_random_uuid(), '{venue2}', 'de', 'zweithaus-venues', 'Kurz.')",
        "INSERT INTO venue_slug_history (id, venue_id, locale, slug) VALUES (gen_random_uuid(), '{venue}', 'de', 'altes-testhaus')",
        "INSERT INTO venue_terms (venue_id, term_group, term_code, is_main) VALUES ('{venue}', 'setting', 'lake', TRUE), ('{venue}', 'building', 'castle', TRUE), ('{venue}', 'building', 'villa', FALSE)",
        "INSERT INTO venue_rating_highlights (venue_id, position, code) VALUES ('{venue}', 1, 'food')",
        "INSERT INTO venue_reviews (id, venue_id, locale, first_name, role, reviewed_month, text) VALUES (gen_random_uuid(), '{venue}', 'de', 'Anna', 'visitor_f', '2026-05-01', 'Schön.')",
        "INSERT INTO rooms (id, venue_id, position) VALUES ('{room}', '{venue}', 1)",
        "INSERT INTO room_translations (id, room_id, locale, name) VALUES (gen_random_uuid(), '{room}', 'de', 'Saal')",
        "INSERT INTO venue_documents (id, venue_id, position, path, size_bytes) VALUES ('{doc}', '{venue}', 1, 'demo/a.pdf', 1000)",
        "INSERT INTO venue_document_translations (id, venue_document_id, locale, title) VALUES (gen_random_uuid(), '{doc}', 'de', 'Preisliste')",
        // A venue photo and a room photo may share a position: the sequences are separate.
        "INSERT INTO venue_photos (id, venue_id, room_id, position, original_path, {photo-cols}) VALUES ('{photo}', '{venue}', NULL, 1, 'demo/a.jpg', {photo-vals}), (gen_random_uuid(), '{venue}', '{room}', 1, 'demo/b.jpg', {photo-vals})",
        "INSERT INTO venue_photo_translations (id, venue_photo_id, locale, alt) VALUES (gen_random_uuid(), '{photo}', 'de', 'Aussenansicht')",
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();

        foreach (self::SEED as $sql) {
            $this->sql($sql);
        }
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    /**
     * Statements that must fail on the named constraint. Only the last statement of a case
     * is expected to fail; the optional third element replaces the constraint name as the
     * expected part of the error message.
     *
     * @return iterable<string, array{0: string, 1: list<string>, 2?: string}>
     */
    public static function rejections(): iterable
    {
        // venues
        // The NOT NULL of the generated tier_rank (CASE without ELSE) fires before chk_venues_tier;
        // either way an unknown tier is rejected. VocabularyChecksTest pins the IN list.
        yield 'unknown tier' => ['chk_venues_tier', ["UPDATE venues SET tier = 'gold' WHERE id = '{venue}'"], '"tier_rank"'];
        yield 'unknown status' => ['chk_venues_status', ["UPDATE venues SET status = 'draft' WHERE id = '{venue}'"]];
        yield 'capacity above 50000' => ['chk_venues_capacity', ["UPDATE venues SET capacity_max = 50001 WHERE id = '{venue}'"]];
        yield 'capacity zero' => ['chk_venues_capacity', ["UPDATE venues SET capacity_max = 0 WHERE id = '{venue}'"]];
        yield 'capacity min above max' => ['chk_venues_capacity', ["UPDATE venues SET capacity_min = 101 WHERE id = '{venue}'"]];
        yield 'zip of three digits' => ['chk_venues_zip', ["UPDATE venues SET zip = '871' WHERE id = '{venue}'"]];
        yield 'lat outside Switzerland' => ['chk_venues_lat', ["UPDATE venues SET lat = 45.79 WHERE id = '{venue}'"]];
        yield 'lng outside Switzerland' => ['chk_venues_lng', ["UPDATE venues SET lng = 10.61 WHERE id = '{venue}'"]];
        yield 'distance above 500 km' => ['chk_venues_distances', ["UPDATE venues SET distance_airport_km = 500.1 WHERE id = '{venue}'"]];
        yield 'negative distance' => ['chk_venues_distances', ["UPDATE venues SET distance_station_km = -0.1 WHERE id = '{venue}'"]];
        yield 'station name without distance' => ['chk_venues_distance_names', ["UPDATE venues SET station_name = 'Stäfa' WHERE id = '{venue}'"]];
        yield 'highway name without distance' => ['chk_venues_distance_names', ["UPDATE venues SET highway_name = 'A3' WHERE id = '{venue}'"]];
        yield 'phone not E.164' => ['chk_venues_phone', ["UPDATE venues SET phone = '044 123 45 67' WHERE id = '{venue}'"]];
        yield 'inquiry email without at' => ['chk_venues_inquiry_email', ["UPDATE venues SET inquiry_email = 'info.example.ch' WHERE id = '{venue}'"]];
        yield 'website without scheme' => ['chk_venues_website', ["UPDATE venues SET website = 'www.example.ch' WHERE id = '{venue}'"]];
        yield 'unknown own catering' => ['chk_venues_own_catering', ["UPDATE venues SET own_catering = 'yes' WHERE id = '{venue}'"]];
        yield 'score without count' => ['chk_venues_rating', ["UPDATE venues SET rating_score = 8.0 WHERE id = '{venue}'"]];
        yield 'count without score' => ['chk_venues_rating', ["UPDATE venues SET rating_count = 3 WHERE id = '{venue}'"]];
        yield 'score above 10' => ['chk_venues_rating', ["UPDATE venues SET rating_score = 10.1, rating_count = 1 WHERE id = '{venue}'"]];
        yield 'negative rating count' => ['chk_venues_rating_count', ["UPDATE venues SET rating_score = 8.0, rating_count = -1 WHERE id = '{venue}'"]];
        yield 'negative room count' => ['chk_venues_room_count', ["UPDATE venues SET room_count = -1 WHERE id = '{venue}'"]];
        yield 'largest room zero' => ['chk_venues_largest_room_m2', ["UPDATE venues SET largest_room_m2 = 0 WHERE id = '{venue}'"]];
        yield 'tallest room above 30 m' => ['chk_venues_max_room_height_m', ["UPDATE venues SET max_room_height_m = 30.01 WHERE id = '{venue}'"]];
        yield 'tallest room height zero' => ['chk_venues_max_room_height_m', ["UPDATE venues SET max_room_height_m = 0 WHERE id = '{venue}'"]];
        yield 'duplicate number' => ['uk_venues_number', ["INSERT INTO venues (id, number, name, tier, status, capacity_max, zip, town_id, canton_code, lat, lng) OVERRIDING SYSTEM VALUE SELECT gen_random_uuid(), number, 'Kopie', 'basic', 'published', 10, '8999', '{town}', 'zh', 47.3, 8.5 FROM venues WHERE id = '{venue}'"]];
        yield 'unknown town' => ['fk_venues_town_id', ["UPDATE venues SET town_id = '{missing}' WHERE id = '{venue}'"]];
        yield 'unknown canton' => ['fk_venues_canton_code', ["UPDATE venues SET canton_code = 'xx' WHERE id = '{venue}'"]];
        yield 'town of a venue is not deleted' => ['fk_venues_town_id', ["DELETE FROM towns WHERE id = '{town}'"]];
        yield 'canton of a venue is not deleted' => ['fk_venues_canton_code', [
            "UPDATE venues SET canton_code = 'ju' WHERE id = '{venue}'",
            "DELETE FROM towns WHERE canton_code = 'ju'",
            "DELETE FROM cantons WHERE code = 'ju'",
        ]];

        // venue_translations
        yield 'second de translation' => ['uk_venue_translations_venue_id_locale', ["INSERT INTO venue_translations (id, venue_id, locale, slug, summary) VALUES (gen_random_uuid(), '{venue}', 'de', 'testhaus-zwei', 'Kurz.')"]];
        yield 'slug taken in the locale' => ['uk_venue_translations_locale_slug', ["UPDATE venue_translations SET slug = 'testhaus-venues' WHERE venue_id = '{venue2}'"]];
        yield 'upper-case locale' => ['chk_venue_translations_locale', ["UPDATE venue_translations SET locale = 'DE' WHERE venue_id = '{venue}'"]];
        yield 'slug with spaces' => ['chk_venue_translations_slug', ["UPDATE venue_translations SET slug = 'Testhaus Venues' WHERE venue_id = '{venue}'"]];
        yield 'slug with double hyphen' => ['chk_venue_translations_slug', ["UPDATE venue_translations SET slug = 'testhaus--venues' WHERE venue_id = '{venue}'"]];
        yield 'translation of unknown venue' => ['fk_venue_translations_venue_id', ["INSERT INTO venue_translations (id, venue_id, locale, slug, summary) VALUES (gen_random_uuid(), '{missing}', 'fr', 'maison', 'Court.')"]];

        // venue_slug_history
        yield 'history slug taken' => ['uk_venue_slug_history_locale_slug', ["INSERT INTO venue_slug_history (id, venue_id, locale, slug) VALUES (gen_random_uuid(), '{venue2}', 'de', 'altes-testhaus')"]];
        yield 'history locale' => ['chk_venue_slug_history_locale', ["UPDATE venue_slug_history SET locale = 'D1' WHERE venue_id = '{venue}'"]];
        yield 'history slug format' => ['chk_venue_slug_history_slug', ["UPDATE venue_slug_history SET slug = 'Altes_Testhaus' WHERE venue_id = '{venue}'"]];
        yield 'history of unknown venue' => ['fk_venue_slug_history_venue_id', ["INSERT INTO venue_slug_history (id, venue_id, locale, slug) VALUES (gen_random_uuid(), '{missing}', 'de', 'weg')"]];

        // taxonomy_terms
        yield 'duplicate term' => ['pk_taxonomy_terms', ["INSERT INTO taxonomy_terms (term_group, code, position) VALUES ('building', 'castle', 99)"]];
        yield 'duplicate term position' => ['uk_taxonomy_terms_term_group_position', ["INSERT INTO taxonomy_terms (term_group, code, position) VALUES ('building', 'igloo', 1)"]];
        yield 'duplicate primary position' => ['uk_taxonomy_terms_term_group_primary_position', ["INSERT INTO taxonomy_terms (term_group, code, position, primary_position) VALUES ('building', 'igloo', 99, 1)"]];
        yield 'unknown term group' => ['chk_taxonomy_terms_term_group', ["INSERT INTO taxonomy_terms (term_group, code, position) VALUES ('colour', 'red', 1)"]];
        yield 'term code not snake case' => ['chk_taxonomy_terms_code', ["INSERT INTO taxonomy_terms (term_group, code, position) VALUES ('building', 'Igloo-Bar', 99)"]];
        yield 'term position zero' => ['chk_taxonomy_terms_position', ["INSERT INTO taxonomy_terms (term_group, code, position) VALUES ('building', 'igloo', 0)"]];
        yield 'primary position zero' => ['chk_taxonomy_terms_primary_position', ["INSERT INTO taxonomy_terms (term_group, code, position, primary_position) VALUES ('building', 'igloo', 99, 0)"]];

        // venue_terms
        yield 'term assigned twice' => ['pk_venue_terms', ["INSERT INTO venue_terms (venue_id, term_group, term_code) VALUES ('{venue}', 'building', 'villa')"]];
        yield 'second main building' => ['uk_venue_terms_venue_id_term_group_main', ["UPDATE venue_terms SET is_main = TRUE WHERE venue_id = '{venue}' AND term_code = 'villa'"]];
        yield 'term of unknown venue' => ['fk_venue_terms_venue_id', ["INSERT INTO venue_terms (venue_id, term_group, term_code) VALUES ('{missing}', 'building', 'villa')"]];
        yield 'unknown term' => ['fk_venue_terms_term', ["INSERT INTO venue_terms (venue_id, term_group, term_code) VALUES ('{venue}', 'building', 'spaceship')"]];
        yield 'term in the wrong group' => ['fk_venue_terms_term', ["INSERT INTO venue_terms (venue_id, term_group, term_code) VALUES ('{venue}', 'setting', 'castle')"]];
        yield 'used term is not deleted' => ['fk_venue_terms_term', ["DELETE FROM taxonomy_terms WHERE term_group = 'building' AND code = 'castle'"]];

        // venue_rating_highlights
        yield 'highlight position taken' => ['pk_venue_rating_highlights', ["INSERT INTO venue_rating_highlights (venue_id, position, code) VALUES ('{venue}', 1, 'value')"]];
        yield 'highlight twice' => ['uk_venue_rating_highlights_venue_id_code', ["INSERT INTO venue_rating_highlights (venue_id, position, code) VALUES ('{venue}', 2, 'food')"]];
        yield 'fourth highlight' => ['chk_venue_rating_highlights_position', ["INSERT INTO venue_rating_highlights (venue_id, position, code) VALUES ('{venue}', 4, 'value')"]];
        yield 'unknown highlight' => ['chk_venue_rating_highlights_code', ["INSERT INTO venue_rating_highlights (venue_id, position, code) VALUES ('{venue}', 2, 'wifi')"]];
        yield 'highlight of unknown venue' => ['fk_venue_rating_highlights_venue_id', ["INSERT INTO venue_rating_highlights (venue_id, position, code) VALUES ('{missing}', 1, 'food')"]];

        // venue_reviews
        yield 'review locale' => ['chk_venue_reviews_locale', ["UPDATE venue_reviews SET locale = '' WHERE venue_id = '{venue}'"]];
        yield 'unknown reviewer role' => ['chk_venue_reviews_role', ["UPDATE venue_reviews SET role = 'owner_f' WHERE venue_id = '{venue}'"]];
        yield 'review month not on day 1' => ['chk_venue_reviews_reviewed_month', ["UPDATE venue_reviews SET reviewed_month = '2026-05-02' WHERE venue_id = '{venue}'"]];
        yield 'review of unknown venue' => ['fk_venue_reviews_venue_id', ["INSERT INTO venue_reviews (id, venue_id, locale, first_name, role, reviewed_month, text) VALUES (gen_random_uuid(), '{missing}', 'de', 'Ben', 'visitor_m', '2026-05-01', 'Gut.')"]];

        // rooms
        yield 'room position taken' => ['uk_rooms_venue_id_position', ["INSERT INTO rooms (id, venue_id, position) VALUES (gen_random_uuid(), '{venue}', 1)"]];
        yield 'room position zero' => ['chk_rooms_position', ["UPDATE rooms SET position = 0 WHERE id = '{room}'"]];
        yield 'room capacity min above max' => ['chk_rooms_capacity', ["UPDATE rooms SET capacity_min = 80, capacity_max = 40 WHERE id = '{room}'"]];
        yield 'room capacity above 50000' => ['chk_rooms_capacity', ["UPDATE rooms SET capacity_max = 50001 WHERE id = '{room}'"]];
        yield 'room area zero' => ['chk_rooms_area_m2', ["UPDATE rooms SET area_m2 = 0 WHERE id = '{room}'"]];
        yield 'room height above 30 m' => ['chk_rooms_height_m', ["UPDATE rooms SET height_m = 30.5 WHERE id = '{room}'"]];
        yield 'unknown floor' => ['chk_rooms_floor_material', ["UPDATE rooms SET floor_material = 'marble' WHERE id = '{room}'"]];
        yield 'seminar min above max' => ['chk_rooms_capacity_seminar', ["UPDATE rooms SET capacity_seminar_min = 50, capacity_seminar_max = 20 WHERE id = '{room}'"]];
        yield 'banquet zero' => ['chk_rooms_capacity_banquet', ["UPDATE rooms SET capacity_banquet_min = 0 WHERE id = '{room}'"]];
        yield 'apero above 50000' => ['chk_rooms_capacity_apero', ["UPDATE rooms SET capacity_apero_max = 50001 WHERE id = '{room}'"]];
        yield 'concert min above max' => ['chk_rooms_capacity_concert', ["UPDATE rooms SET capacity_concert_min = 300, capacity_concert_max = 200 WHERE id = '{room}'"]];
        yield 'room of unknown venue' => ['fk_rooms_venue_id', ["INSERT INTO rooms (id, venue_id, position) VALUES (gen_random_uuid(), '{missing}', 1)"]];

        // room_translations
        yield 'second de room name' => ['uk_room_translations_room_id_locale', ["INSERT INTO room_translations (id, room_id, locale, name) VALUES (gen_random_uuid(), '{room}', 'de', 'Foyer')"]];
        yield 'room translation locale' => ['chk_room_translations_locale', ["UPDATE room_translations SET locale = 'D' WHERE room_id = '{room}'"]];
        yield 'translation of unknown room' => ['fk_room_translations_room_id', ["INSERT INTO room_translations (id, room_id, locale, name) VALUES (gen_random_uuid(), '{missing}', 'de', 'Foyer')"]];

        // venue_documents
        yield 'document position taken' => ['uk_venue_documents_venue_id_position', ["INSERT INTO venue_documents (id, venue_id, position, path, size_bytes) VALUES (gen_random_uuid(), '{venue}', 1, 'demo/b.pdf', 10)"]];
        yield 'document position zero' => ['chk_venue_documents_position', ["UPDATE venue_documents SET position = 0 WHERE id = '{doc}'"]];
        yield 'document not a pdf' => ['chk_venue_documents_path', ["UPDATE venue_documents SET path = 'demo/a.docx' WHERE id = '{doc}'"]];
        yield 'empty document' => ['chk_venue_documents_size_bytes', ["UPDATE venue_documents SET size_bytes = 0 WHERE id = '{doc}'"]];
        yield 'document of unknown venue' => ['fk_venue_documents_venue_id', ["INSERT INTO venue_documents (id, venue_id, position, path, size_bytes) VALUES (gen_random_uuid(), '{missing}', 1, 'demo/b.pdf', 10)"]];

        // venue_document_translations
        yield 'second de document title' => ['uk_venue_document_translations_venue_document_id_locale', ["INSERT INTO venue_document_translations (id, venue_document_id, locale, title) VALUES (gen_random_uuid(), '{doc}', 'de', 'Menü')"]];
        yield 'document title locale' => ['chk_venue_document_translations_locale', ["UPDATE venue_document_translations SET locale = '12' WHERE venue_document_id = '{doc}'"]];
        yield 'title of unknown document' => ['fk_venue_document_translations_venue_document_id', ["INSERT INTO venue_document_translations (id, venue_document_id, locale, title) VALUES (gen_random_uuid(), '{missing}', 'de', 'Menü')"]];

        // venue_photos
        yield 'venue photo position taken (NULLS NOT DISTINCT)' => ['uk_venue_photos_venue_id_room_id_position', ["INSERT INTO venue_photos (id, venue_id, room_id, position, original_path, {photo-cols}) VALUES (gen_random_uuid(), '{venue}', NULL, 1, 'demo/c.jpg', {photo-vals})"]];
        yield 'room photo position taken' => ['uk_venue_photos_venue_id_room_id_position', ["INSERT INTO venue_photos (id, venue_id, room_id, position, original_path, {photo-cols}) VALUES (gen_random_uuid(), '{venue}', '{room}', 1, 'demo/c.jpg', {photo-vals})"]];
        yield 'photo position zero' => ['chk_venue_photos_position', ["UPDATE venue_photos SET position = 0 WHERE id = '{photo}'"]];
        yield 'photo of unknown venue' => ['fk_venue_photos_venue_id', ["INSERT INTO venue_photos (id, venue_id, position, original_path, {photo-cols}) VALUES (gen_random_uuid(), '{missing}', 1, 'demo/c.jpg', {photo-vals})"]];
        yield 'photo width zero' => ['chk_venue_photos_width', ["UPDATE venue_photos SET width = 0 WHERE id = '{photo}'"]];
        yield 'photo height negative' => ['chk_venue_photos_height', ["UPDATE venue_photos SET height = -1 WHERE id = '{photo}'"]];
        yield 'dominant colour upper case' => ['chk_venue_photos_dominant_color', ["UPDATE venue_photos SET dominant_color = '#B9A58C' WHERE id = '{photo}'"]];
        yield 'dominant colour without hash' => ['chk_venue_photos_dominant_color', ["UPDATE venue_photos SET dominant_color = 'b9a58c' WHERE id = '{photo}'"]];
        yield 'variants a list' => ['chk_venue_photos_variants', ["UPDATE venue_photos SET variants = '[]' WHERE id = '{photo}'"]];
        yield 'variants without widths' => ['chk_venue_photos_variants', ["UPDATE venue_photos SET variants = '{\"widths\": [], \"formats\": [\"jpg\"]}' WHERE id = '{photo}'"]];
        yield 'variants without jpg' => ['chk_venue_photos_variants', ["UPDATE venue_photos SET variants = '{\"widths\": [360], \"formats\": [\"webp\"]}' WHERE id = '{photo}'"]];
        yield 'variants without widths key' => ['chk_venue_photos_variants', ["UPDATE venue_photos SET variants = '{\"formats\": [\"jpg\"]}' WHERE id = '{photo}'"]];
        yield 'variants widths a number' => ['chk_venue_photos_variants', ["UPDATE venue_photos SET variants = '{\"widths\": 360, \"formats\": [\"jpg\"]}' WHERE id = '{photo}'"]];
        yield 'photo of unknown room' => ['fk_venue_photos_room_id', ["INSERT INTO venue_photos (id, venue_id, room_id, position, original_path, {photo-cols}) VALUES (gen_random_uuid(), '{venue}', '{missing}', 2, 'demo/c.jpg', {photo-vals})"]];

        // venue_photo_translations
        yield 'second de alt' => ['uk_venue_photo_translations_venue_photo_id_locale', ["INSERT INTO venue_photo_translations (id, venue_photo_id, locale, alt) VALUES (gen_random_uuid(), '{photo}', 'de', 'Garten')"]];
        yield 'alt locale' => ['chk_venue_photo_translations_locale', ["UPDATE venue_photo_translations SET locale = 'De' WHERE venue_photo_id = '{photo}'"]];
        yield 'alt of unknown photo' => ['fk_venue_photo_translations_venue_photo_id', ["INSERT INTO venue_photo_translations (id, venue_photo_id, locale, alt) VALUES (gen_random_uuid(), '{missing}', 'de', 'Garten')"]];
    }

    /**
     * Boundary values and combinations the constraints must let through.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function acceptances(): iterable
    {
        yield 'capacity 1 to 50000' => [["UPDATE venues SET capacity_min = 1, capacity_max = 50000 WHERE id = '{venue}'"]];
        yield 'capacity min equals max' => [["UPDATE venues SET capacity_min = 100 WHERE id = '{venue}'"]];
        yield 'Swiss box corners' => [["UPDATE venues SET lat = 45.8, lng = 10.6 WHERE id = '{venue}'", "UPDATE venues SET lat = 47.9, lng = 5.9 WHERE id = '{venue}'"]];
        yield 'distances 0 and 500 with names' => [["UPDATE venues SET distance_station_km = 0, station_name = 'Stäfa', distance_highway_km = 500, highway_name = 'A3', distance_airport_km = 12.5, airport_name = NULL WHERE id = '{venue}'"]];
        yield 'contact fields' => [["UPDATE venues SET phone = '+41441234567', inquiry_email = 'info@example.ch', website = 'https://example.ch', contact_person = 'Frau Muster' WHERE id = '{venue}'"]];
        yield 'rating 0 and 10 with count 0' => [["UPDATE venues SET rating_score = 0, rating_count = 0 WHERE id = '{venue}'", "UPDATE venues SET rating_score = 10.0, rating_count = 4766 WHERE id = '{venue}'"]];
        yield 'room facts at their bounds' => [["UPDATE venues SET room_count = 0, largest_room_m2 = 50000, max_room_height_m = 30 WHERE id = '{venue}'"]];
        yield 'FAQ answers' => [["UPDATE venues SET own_catering = 'fixed_caterer', daylight = FALSE WHERE id = '{venue}'"]];
        yield 'same slug in another locale' => [["INSERT INTO venue_translations (id, venue_id, locale, slug, summary) VALUES (gen_random_uuid(), '{venue2}', 'fr', 'testhaus-venues', 'Court.')"]];
        yield 'many terms without primary position' => [["INSERT INTO taxonomy_terms (term_group, code, position) VALUES ('building', 'igloo', 40), ('building', 'treehouse', 41)"]];
        yield 'main setting and main building, other venue main building' => [["INSERT INTO venue_terms (venue_id, term_group, term_code, is_main) VALUES ('{venue2}', 'building', 'castle', TRUE), ('{venue2}', 'setting', 'lake', TRUE), ('{venue2}', 'setting', 'city', FALSE)"]];
        yield 'three highlights' => [["INSERT INTO venue_rating_highlights (venue_id, position, code) VALUES ('{venue}', 2, 'value'), ('{venue}', 3, 'location')"]];
        yield 'room at its bounds' => [["UPDATE rooms SET capacity_min = 1, capacity_max = 50000, area_m2 = 50000, height_m = 30, floor_material = 'parquet', capacity_seminar_min = 20, capacity_seminar_max = 20, capacity_concert_max = 50000 WHERE id = '{room}'"]];
        yield 'photo with a single width in webp and jpg' => [["UPDATE venue_photos SET width = 300, height = 1, dominant_color = '#000000', variants = '{\"widths\": [300], \"formats\": [\"webp\", \"jpg\"]}' WHERE id = '{photo}'"]];
        yield 'second venue photo' => [["INSERT INTO venue_photos (id, venue_id, room_id, position, original_path, {photo-cols}) VALUES (gen_random_uuid(), '{venue}', NULL, 2, 'demo/c.jpg', {photo-vals}), (gen_random_uuid(), '{venue2}', NULL, 1, 'demo/d.jpg', {photo-vals})"]];
    }

    /**
     * @param list<string> $statements
     */
    #[DataProvider('rejections')]
    public function test_constraint_rejects(string $constraint, array $statements, ?string $message = null): void
    {
        $last = array_pop($statements);
        self::assertNotNull($last);
        foreach ($statements as $sql) {
            $this->sql($sql);
        }

        try {
            $this->sql($last);
            self::fail(\sprintf('The statement should violate %s.', $constraint));
        } catch (DriverException $exception) {
            self::assertStringContainsString($message ?? '"'.$constraint.'"', $exception->getMessage());
        }
    }

    /**
     * @param list<string> $statements
     */
    #[DataProvider('acceptances')]
    public function test_constraints_accept(array $statements): void
    {
        foreach ($statements as $sql) {
            self::assertGreaterThan(0, $this->sql($sql), $sql);
        }
    }

    public function test_every_constraint_has_a_rejection_case(): void
    {
        $tables = "'".implode("', '", self::TABLES)."'";
        $constraints = $this->connection->fetchFirstColumn(
            "SELECT conname FROM pg_constraint WHERE conrelid::regclass::text IN ({$tables}) AND contype IN ('c', 'u', 'f')",
        );
        $primaryKeys = $this->connection->fetchFirstColumn(
            "SELECT conname FROM pg_constraint WHERE conrelid::regclass::text IN ({$tables}) AND contype = 'p'",
        );
        // The partial unique on venue_terms is an index, not a constraint.
        $uniqueIndexes = $this->connection->fetchFirstColumn(
            "SELECT indexname FROM pg_indexes WHERE tablename IN ({$tables}) AND indexname LIKE 'uk\\_%'",
        );
        $names = array_values(array_unique([...$constraints, ...$uniqueIndexes]));
        $tested = array_values(array_unique(array_map(static fn (array $case): string => $case[0], iterator_to_array(self::rejections()))));

        self::assertContains('uk_venue_terms_venue_id_term_group_main', $names);
        self::assertCount(13, $primaryKeys);
        foreach ($names as $name) {
            self::assertContains($name, $tested, $name.' has no rejection case');
        }
        foreach ($tested as $name) {
            self::assertContains($name, [...$names, ...$primaryKeys], $name.' is not a constraint of the schema');
        }
    }

    public function test_deleting_a_venue_cascades_to_every_child_table(): void
    {
        $this->sql("INSERT INTO venue_photo_translations (id, venue_photo_id, locale, alt) SELECT gen_random_uuid(), id, 'de', 'Saal' FROM venue_photos WHERE room_id = '{room}'");
        $before = $this->childRowCounts();
        foreach ($before as $table => $count) {
            self::assertGreaterThan(0, $count, $table);
        }

        $this->sql("DELETE FROM venues WHERE id = '{venue}'");

        self::assertSame(array_fill_keys(array_keys($before), 0), $this->childRowCounts());
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM venue_translations WHERE venue_id = '".self::IDS['{venue2}']."'"));
    }

    public function test_deleting_a_room_removes_its_photos_and_names(): void
    {
        $this->sql("DELETE FROM rooms WHERE id = '{room}'");

        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM venue_photos WHERE room_id IS NOT NULL AND venue_id = '".self::IDS['{venue}']."'"));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM room_translations WHERE room_id = '".self::IDS['{room}']."'"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM venue_photos WHERE venue_id = '".self::IDS['{venue}']."'"));
    }

    public function test_tier_rank_is_generated_from_the_tier(): void
    {
        foreach (Tier::cases() as $tier) {
            $this->sql("UPDATE venues SET tier = '{$tier->value}' WHERE id = '{venue}'");

            self::assertSame($tier->rank(), (int) $this->connection->fetchOne("SELECT tier_rank FROM venues WHERE id = '".self::IDS['{venue}']."'"), $tier->value);
        }
    }

    public function test_number_is_assigned_on_insert_and_cannot_be_chosen(): void
    {
        $numbers = $this->connection->fetchFirstColumn("SELECT number FROM venues WHERE id IN ('".self::IDS['{venue}']."', '".self::IDS['{venue2}']."') ORDER BY name");
        self::assertCount(2, $numbers);
        self::assertGreaterThan((int) $numbers[0], (int) $numbers[1]);

        try {
            $this->sql("INSERT INTO venues (id, number, name, tier, status, capacity_max, zip, town_id, canton_code, lat, lng) VALUES (gen_random_uuid(), 424242, 'Wunschnummer', 'basic', 'published', 10, '8999', '{town}', 'zh', 47.3, 8.5)");
            self::fail('An explicit number should be rejected (GENERATED ALWAYS).');
        } catch (DriverException $exception) {
            self::assertStringContainsString('"number"', $exception->getMessage());
        }
    }

    /**
     * @return array<string, int>
     */
    private function childRowCounts(): array
    {
        $venue = self::IDS['{venue}'];
        $queries = [
            'venue_translations' => "SELECT count(*) FROM venue_translations WHERE venue_id = '{$venue}'",
            'venue_slug_history' => "SELECT count(*) FROM venue_slug_history WHERE venue_id = '{$venue}'",
            'venue_terms' => "SELECT count(*) FROM venue_terms WHERE venue_id = '{$venue}'",
            'venue_rating_highlights' => "SELECT count(*) FROM venue_rating_highlights WHERE venue_id = '{$venue}'",
            'venue_reviews' => "SELECT count(*) FROM venue_reviews WHERE venue_id = '{$venue}'",
            'rooms' => "SELECT count(*) FROM rooms WHERE venue_id = '{$venue}'",
            'room_translations' => "SELECT count(*) FROM room_translations WHERE room_id = '".self::IDS['{room}']."'",
            'venue_documents' => "SELECT count(*) FROM venue_documents WHERE venue_id = '{$venue}'",
            'venue_document_translations' => "SELECT count(*) FROM venue_document_translations WHERE venue_document_id = '".self::IDS['{doc}']."'",
            'venue_photos' => "SELECT count(*) FROM venue_photos WHERE venue_id = '{$venue}'",
            'venue_photo_translations (venue photo)' => "SELECT count(*) FROM venue_photo_translations WHERE venue_photo_id = '".self::IDS['{photo}']."'",
            'venue_photo_translations (room photo)' => 'SELECT count(*) FROM venue_photo_translations t JOIN venue_photos p ON p.id = t.venue_photo_id WHERE p.room_id = \''.self::IDS['{room}']."'",
        ];

        return array_map(fn (string $sql): int => (int) $this->connection->fetchOne($sql), $queries);
    }

    private function sql(string $sql): int
    {
        return (int) $this->connection->executeStatement(strtr($sql, [...self::IDS, ...self::PHOTO_COLUMNS]));
    }
}
