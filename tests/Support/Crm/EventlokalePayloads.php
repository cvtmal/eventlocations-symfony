<?php

declare(strict_types=1);

namespace App\Tests\Support\Crm;

/**
 * Invented eventlokale API payloads in the shapes the crawler reads (#49). Every name, address,
 * text, phone and file is made up: no real eventlokale content may enter the tests.
 */
final class EventlokalePayloads
{
    /** The marker of the reviewer comment that must never reach the CSV. */
    public const string SECRET_COMMENT = 'ERFUNDENER-BEWERTUNGSKOMMENTAR';

    /**
     * One item of `/search/draw`'s `data.data`.
     *
     * @param array<string, mixed> $overrides merged into the defaults; a list replaces a list
     *
     * @return array<string, mixed>
     */
    public static function listItem(int $id, array $overrides = []): array
    {
        return self::merge([
            'id' => $id,
            'location_id' => $id,
            'entity_type' => 'location',
            'name' => 'Testschloss Musterberg '.$id,
            'short_name' => 'Musterberg '.$id,
            'slug' => 'testschloss-musterberg-'.$id,
            'category_id' => 1,
            'category_slug' => 'Schweiz',
            'category_name' => 'Schweiz',
            'type' => 'Premium',
            'boost' => 2,
            'zip' => '9999',
            'place' => 'Musterdorf',
            'region_name' => 'Musterkanton',
            'lat' => '47.0000000',
            'lng' => '8.0000000',
            'distance_airport' => '12.5',
            'distance_train_station' => '1.2',
            'distance_highway' => '3.4',
            // Comma-separated strings, as the API serves them.
            'all_taxonomies_ids' => '1,11,21,31,41,51,61',
            'suitable_for_ids' => '370',
            'highlights_ids' => '91',
            'min_capacity' => 10,
            'max_capacity' => 150,
            'max_area' => 120,
            'max_height' => '4.5',
            'room_number' => 2,
            'summary' => "Ein erfundenes Schloss &amp; <b>Garten</b>  mit\n Seeblick.",
            'note' => 5,
            'likes' => 12,
            'all_photos_long' => '11|test-11.jpg|image/jpeg%12|test-12.jpg|image/jpeg',
        ], $overrides);
    }

    /**
     * The Laravel paginator envelope `/search/draw` answers with.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public static function searchPage(array $items, int $page, int $lastPage, int $total): array
    {
        return [
            'data' => [
                'current_page' => $page,
                'data' => $items,
                'from' => 1,
                'last_page' => $lastPage,
                'per_page' => 200,
                'to' => \count($items),
                'total' => $total,
            ],
            'draw' => 1,
            'page_items' => \count($items),
        ];
    }

    /**
     * One `/{id}/get` body.
     *
     * @param array<string, mixed> $overrides merged into the defaults; a list replaces a list
     *
     * @return array<string, mixed>
     */
    public static function detail(int $id, array $overrides = []): array
    {
        return self::merge([
            'general_data' => [
                'full_name' => 'Testschloss Musterberg am See '.$id,
                'award_name' => null,
                'website' => 'https://general.example.test',
            ],
            'address' => [
                'street' => 'Musterweg',
                'street_number' => '7a',
                'zip' => '9999',
                'place' => 'Musterdorf',
            ],
            'properties' => [
                'name_train_station' => 'Musterdorf Bahnhof',
            ],
            'rooms' => [
                [
                    'room' => 'Rittersaal',
                    'floor' => 'EG',
                    'area' => 120,
                    'height' => '4.5',
                    'banquet_min' => 20,
                    'banquet_max' => 100,
                    'apero_min' => null,
                    'apero_max' => 150,
                    'concert_min' => null,
                    'concert_max' => null,
                    'seminar_min' => 10,
                    'seminar_max' => 80,
                    'min_capacity' => 10,
                    'max_capacity' => 150,
                ],
                [
                    'room' => 'Turmzimmer',
                    'floor' => null,
                    'area' => 40,
                    'height' => null,
                    'banquet_min' => null,
                    'banquet_max' => null,
                    'apero_min' => null,
                    'apero_max' => null,
                    'concert_min' => null,
                    'concert_max' => null,
                    'seminar_min' => null,
                    'seminar_max' => null,
                    'min_capacity' => null,
                    'max_capacity' => 20,
                ],
            ],
            'description' => [
                'summary' => 'Ein erfundenes Schloss.',
                'description' => 'Ein erfundener   Text über das Testschloss.',
            ],
            'faq' => [
                'parking' => 'Genügend Parkplätze',
                'own_catering' => 1,
                'free_parking' => 0,
                'barrier_free' => true,
                'daylight' => false,
                'single_rooms' => null,
                'double_rooms' => 4,
            ],
            'owner' => [
                'company' => 'Muster Events GmbH',
                'title' => 'Frau',
                'first_name' => 'Erika',
                'last_name' => 'Muster',
                'function_name' => 'Leitung Events',
                'phone' => '+41 00 000 00 00',
                'mobile' => '000 000 00 00',
                'email' => 'info@example.test',
                'website' => 'www.example.test',
            ],
            'media' => [
                'photos' => [
                    ['id' => 103, 'order_column' => 3, 'original_url' => '/storage/103/test-3.jpg'],
                    ['id' => 101, 'order_column' => 1, 'original_url' => '/storage/101/test-1.jpg'],
                    ['id' => 102, 'order_column' => 2, 'original_url' => '/storage/102/test-2.jpg'],
                ],
                'documents' => [
                    ['id' => 201, 'original_url' => '/storage/201/test-menu.pdf'],
                ],
                'youtube_links' => [
                    ['url' => 'https://video.example.test/watch/1'],
                ],
                'location_video' => ['url' => 'https://video.example.test/2'],
                'vr360' => ['url' => 'https://vr.example.test/3'],
            ],
            'id' => $id,
            'updated_at' => '2026-01-02T03:04:05.000000Z',
            'access_count' => 999,
            'sla_summary' => [
                'comments' => [
                    ['name' => 'Erfundene Person', 'comment' => self::SECRET_COMMENT],
                ],
            ],
        ], $overrides);
    }

    /**
     * The `/all-taxonomies` body, one or two German terms per group the CSV reads.
     *
     * @return array<string, mixed>
     */
    public static function taxonomies(): array
    {
        $term = static fn (int $id, string $name): array => ['id' => $id, 'name' => $name, 'name_fr' => '', 'name_it' => '', 'slug' => 'term-'.$id];

        return [
            'taxonomies' => [
                'buildings' => [$term(1, 'Schloss'), $term(2, 'Scheune')],
                'locations' => [$term(11, 'Am See')],
                'suitable_for' => [$term(370, 'Hochzeit'), $term(371, 'Firmenanlass')],
                'infrastructures' => [$term(21, 'Garten')],
                'technologies' => [$term(31, 'Beamer')],
                'furniture' => [$term(41, 'Stehtische')],
                'surroundings' => [$term(51, 'Wald')],
                'accesses' => [$term(61, 'Rollstuhlgängig')],
                'sla_rating' => [$term(91, 'Erfundenes Highlight')],
            ],
            'filter_taxonomies' => [],
            'event_taxonomies' => [],
        ];
    }

    /**
     * Recursive for maps, a replacement for lists and scalars.
     *
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $base[$key] = \is_array($value) && !array_is_list($value) && \is_array($base[$key] ?? null)
                ? self::merge($base[$key], $value)
                : $value;
        }

        return $base;
    }
}
