<?php

declare(strict_types=1);

namespace App\Crm;

/**
 * Turns the EventlokaleCache into the owner's private list (#49): one row per listed venue,
 * sorted by eventlokale id, in CsvStream's dialect (`;`, UTF-8 BOM, formula guard). The payloads
 * are undocumented, so a missing key gives an empty cell, never an error; a column empty in every
 * row is reported, the cheap sign that a key name changed. Reviewer comments, contract data and
 * internal counters are never copied.
 */
final class EventlokaleCsv
{
    public const string FILE = 'owner/eventlokale-hochzeit.csv';

    private const string BASE_URL = EventlokaleClient::BASE_URL;

    public const array HEADER = [
        'eventlokale_id', 'name', 'short_name', 'eventlokale_url', 'tier', 'boost', 'detail_status',
        'street', 'street_number', 'zip', 'place', 'region', 'lat', 'lng',
        'distance_airport_km', 'distance_train_station_km', 'distance_highway_km', 'train_station_name',
        'building_types', 'settings', 'suitable_for', 'infrastructure', 'technology', 'furniture', 'surroundings', 'access', 'highlights',
        'min_capacity', 'max_capacity', 'max_area_m2', 'max_height_m', 'room_count', 'rooms',
        'summary', 'description',
        'parking', 'own_catering', 'free_parking', 'barrier_free', 'daylight', 'single_rooms', 'double_rooms',
        'award', 'rating_note', 'likes',
        'contact_company', 'contact_salutation', 'contact_first_name', 'contact_last_name', 'contact_function',
        'phone', 'mobile', 'email', 'website',
        'photo_count', 'cover_photo_url', 'photo_urls', 'document_urls', 'video_urls',
        'eventlokale_updated_at', 'crawled_at',
    ];

    /** CSV column => list item key. */
    private const array LIST_COLUMNS = [
        'short_name' => 'short_name',
        'tier' => 'type',
        'boost' => 'boost',
        'zip' => 'zip',
        'place' => 'place',
        'region' => 'region_name',
        'lat' => 'lat',
        'lng' => 'lng',
        'min_capacity' => 'min_capacity',
        'max_capacity' => 'max_capacity',
        'max_area_m2' => 'max_area',
        'max_height_m' => 'max_height',
        'room_count' => 'room_number',
        'summary' => 'summary',
        'rating_note' => 'note',
        'likes' => 'likes',
    ];

    /** CSV column => list item key; research saw them only as `distance_*`. */
    private const array DISTANCE_COLUMNS = [
        'distance_airport_km' => 'distance_airport',
        'distance_train_station_km' => 'distance_train_station',
        'distance_highway_km' => 'distance_highway',
    ];

    /** CSV column => taxonomy group, the terms resolved from the list item's ids. */
    private const array TERM_COLUMNS = [
        'building_types' => 'buildings',
        'settings' => 'locations',
        'suitable_for' => 'suitable_for',
        'infrastructure' => 'infrastructures',
        'technology' => 'technologies',
        'furniture' => 'furniture',
        'surroundings' => 'surroundings',
        'access' => 'accesses',
    ];

    /** CSV column => detail path. */
    private const array DETAIL_COLUMNS = [
        'street' => 'address.street',
        'street_number' => 'address.street_number',
        'train_station_name' => 'properties.name_train_station',
        'description' => 'description.description',
        'award' => 'general_data.award_name',
        'contact_company' => 'owner.company',
        'contact_salutation' => 'owner.title',
        'contact_first_name' => 'owner.first_name',
        'contact_last_name' => 'owner.last_name',
        'contact_function' => 'owner.function_name',
        'phone' => 'owner.phone',
        'mobile' => 'owner.mobile',
        'email' => 'owner.email',
        'eventlokale_updated_at' => 'updated_at',
    ];

    /** The FAQ answers, each a column of the same name. */
    private const array FAQ_KEYS = ['parking', 'own_catering', 'free_parking', 'barrier_free', 'daylight', 'single_rooms', 'double_rooms'];

    /** Room key prefix => label of a min-max pair. */
    private const array ROOM_PAIRS = ['banquet' => 'Bankett', 'apero' => 'Apéro', 'concert' => 'Konzert', 'seminar' => 'Seminar'];

    /**
     * @return array{rows: int, emptyColumns: list<string>}
     */
    public function write(string $root): array
    {
        $cache = new EventlokaleCache($root);
        $termNames = self::termNames($cache->readTaxonomies());
        $items = $cache->readIndex()['items'];
        usort($items, static fn (array $a, array $b): int => self::int($a['id'] ?? null) <=> self::int($b['id'] ?? null));

        $rows = [];
        foreach ($items as $item) {
            $id = self::int($item['id'] ?? null);
            $rows[] = $this->row($item, $cache->readDetail($id), $cache->isGone($id), $cache->detailMtime($id), $termNames);
        }

        $stream = fopen('php://temp', 'w+');
        if (false === $stream) {
            throw new \RuntimeException('Cannot open a temporary stream.');
        }
        CsvStream::write($stream, self::HEADER, $rows);
        rewind($stream);
        $cache->writeFile(self::FILE, (string) stream_get_contents($stream));
        fclose($stream);

        $empty = array_values(array_filter(
            self::HEADER,
            static fn (string $column, int $position): bool => [] === array_filter($rows, static fn (array $row): bool => null !== $row[$position]),
            \ARRAY_FILTER_USE_BOTH,
        ));

        return ['rows' => \count($rows), 'emptyColumns' => $empty];
    }

    /**
     * @param array<mixed>                      $item
     * @param array<mixed>|null                 $detail
     * @param array<string, array<int, string>> $termNames group => id => German name
     *
     * @return list<string|null> in HEADER order
     */
    private function row(array $item, ?array $detail, bool $gone, ?int $mtime, array $termNames): array
    {
        $id = self::int($item['id'] ?? null);
        $cells = [
            'eventlokale_id' => (string) $id,
            'name' => self::clean(self::at($detail, 'general_data.full_name')) ?? self::clean($item['name'] ?? null),
            'eventlokale_url' => self::publicUrl($item),
            'detail_status' => null !== $detail ? 'ok' : ($gone ? 'gone' : 'missing'),
            'rooms' => self::rooms(self::at($detail, 'rooms')),
            'website' => self::clean(self::at($detail, 'owner.website')) ?? self::clean(self::at($detail, 'general_data.website')),
            'highlights' => self::terms(self::ids($item['highlights_ids'] ?? null), $termNames['sla_rating'] ?? []),
            'document_urls' => self::join(array_map(
                static fn (mixed $document): ?string => self::absolute(self::at($document, 'original_url')),
                self::list(self::at($detail, 'media.documents')),
            )),
            'video_urls' => self::join([
                ...array_map(static fn (mixed $link): ?string => self::absolute(self::at($link, 'url')), self::list(self::at($detail, 'media.youtube_links'))),
                self::absolute(self::at($detail, 'media.location_video.url')),
                self::absolute(self::at($detail, 'media.vr360.url')),
            ]),
            'crawled_at' => null === $mtime ? null : (new \DateTimeImmutable('@'.$mtime))->setTimezone(new \DateTimeZone('Europe/Zurich'))->format(\DATE_ATOM),
        ];
        foreach ([...self::LIST_COLUMNS, ...self::DISTANCE_COLUMNS] as $column => $key) {
            $cells[$column] = self::clean($item[$key] ?? null);
        }
        foreach (self::DETAIL_COLUMNS as $column => $path) {
            $cells[$column] = self::clean(self::at($detail, $path));
        }
        foreach (self::FAQ_KEYS as $key) {
            $cells[$key] = self::yesNo(self::at($detail, 'faq.'.$key));
        }
        $termIds = [...self::ids($item['all_taxonomies_ids'] ?? null), ...self::ids($item['suitable_for_ids'] ?? null)];
        foreach (self::TERM_COLUMNS as $column => $group) {
            $cells[$column] = self::terms($termIds, $termNames[$group] ?? []);
        }
        $photos = null !== $detail ? self::detailPhotos($detail) : self::listPhotos($item);
        $cells['photo_count'] = (string) \count($photos);
        $cells['cover_photo_url'] = $photos[0] ?? null;
        $cells['photo_urls'] = self::join($photos);

        return array_map(static fn (string $column): ?string => $cells[$column] ?? null, self::HEADER);
    }

    /**
     * @param array<mixed> $taxonomies
     *
     * @return array<string, array<int, string>>
     */
    private static function termNames(array $taxonomies): array
    {
        $names = [];
        $groups = self::at($taxonomies, 'taxonomies');
        foreach (\is_array($groups) ? $groups : [] as $group => $terms) {
            foreach (\is_array($terms) ? $terms : [] as $term) {
                $id = self::at($term, 'id');
                $name = self::clean(self::at($term, 'name'));
                if (\is_int($id) && null !== $name) {
                    $names[(string) $group][$id] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * The names of those ids that belong to the group, in the item's order.
     *
     * @param list<int>          $ids
     * @param array<int, string> $names
     */
    private static function terms(array $ids, array $names): ?string
    {
        $found = [];
        foreach ($ids as $id) {
            if (isset($names[$id])) {
                $found[$id] = $names[$id];
            }
        }

        return self::join(array_values($found));
    }

    /**
     * Photos by order_column, then id, as absolute URLs.
     *
     * @param array<mixed> $detail
     *
     * @return list<string>
     */
    private static function detailPhotos(array $detail): array
    {
        $photos = array_values(array_filter(self::list(self::at($detail, 'media.photos')), \is_array(...)));
        usort($photos, static fn (array $a, array $b): int => [self::int($a['order_column'] ?? null), self::int($a['id'] ?? null)]
            <=> [self::int($b['order_column'] ?? null), self::int($b['id'] ?? null)]);

        return array_values(array_filter(array_map(static fn (array $photo): ?string => self::absolute($photo['original_url'] ?? null), $photos)));
    }

    /**
     * The up to three photos of `all_photos_long` ("<mediaId>|<file_name>|<mime>%…").
     *
     * @param array<mixed> $item
     *
     * @return list<string>
     */
    private static function listPhotos(array $item): array
    {
        $photos = [];
        $long = $item['all_photos_long'] ?? null;
        foreach (\is_string($long) ? explode('%', $long) : [] as $entry) {
            $parts = explode('|', $entry);
            if (isset($parts[1]) && '' !== trim($parts[0]) && '' !== trim($parts[1])) {
                $photos[] = self::BASE_URL.'/storage/'.trim($parts[0]).'/'.trim($parts[1]);
            }
        }

        return $photos;
    }

    /**
     * One entry per room, the null parts left out.
     */
    private static function rooms(mixed $rooms): ?string
    {
        $entries = [];
        foreach (self::list($rooms) as $room) {
            $parts = [];
            if (null !== $floor = self::clean(self::at($room, 'floor'))) {
                $parts[] = 'Etage '.$floor;
            }
            if (null !== $area = self::clean(self::at($room, 'area'))) {
                $parts[] = $area.' m²';
            }
            if (null !== $height = self::clean(self::at($room, 'height'))) {
                $parts[] = $height.' m';
            }
            foreach (self::ROOM_PAIRS as $prefix => $label) {
                if (null !== $range = self::range(self::at($room, $prefix.'_min'), self::at($room, $prefix.'_max'))) {
                    $parts[] = $label.' '.$range;
                }
            }
            if (null !== $range = self::range(self::at($room, 'min_capacity'), self::at($room, 'max_capacity'))) {
                $parts[] = $range.' Pers.';
            }
            $name = self::clean(self::at($room, 'room'));
            $entries[] = null === $name ? implode(', ', $parts) : $name.([] === $parts ? '' : ': '.implode(', ', $parts));
        }

        return self::join($entries);
    }

    /** "min-max", "ab min" or "bis max". */
    private static function range(mixed $min, mixed $max): ?string
    {
        $min = self::clean($min);
        $max = self::clean($max);

        return match (true) {
            null !== $min && null !== $max => $min.'-'.$max,
            null !== $min => 'ab '.$min,
            null !== $max => 'bis '.$max,
            default => null,
        };
    }

    /**
     * @param array<mixed> $item
     */
    private static function publicUrl(array $item): ?string
    {
        $parts = [$item['category_id'] ?? null, $item['category_slug'] ?? null, $item['id'] ?? null, $item['slug'] ?? null];
        $parts = array_map(self::clean(...), $parts);
        if (\in_array(null, $parts, true)) {
            return null;
        }

        return \sprintf('%s/site/_eventlokale/%s/%s/%s/%s.html', self::BASE_URL, ...$parts);
    }

    private static function yesNo(mixed $value): ?string
    {
        return match ($value) {
            true, 1, '1' => 'ja',
            false, 0, '0' => 'nein',
            default => self::clean($value),
        };
    }

    private static function absolute(mixed $url): ?string
    {
        $url = self::clean($url);
        if (null !== $url && str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return self::BASE_URL.$url;
        }

        return $url;
    }

    /**
     * Entities decoded, tags stripped, whitespace collapsed; empty is null.
     */
    private static function clean(mixed $value): ?string
    {
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (!\is_string($value)) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'))));

        return '' === $text ? null : $text;
    }

    /**
     * @param list<string|null> $values
     */
    private static function join(array $values): ?string
    {
        $values = array_filter($values, static fn (?string $value): bool => null !== $value && '' !== $value);

        return [] === $values ? null : implode(' | ', $values);
    }

    /**
     * A dotted path into nested arrays; null when any step is missing.
     */
    private static function at(mixed $data, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!\is_array($data) || !\array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    /**
     * Term ids as the list item holds them, a comma-separated string ("1,11,21"), or as an array.
     *
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        $ids = \is_string($value) ? explode(',', $value) : self::list($value);

        return array_values(array_map(intval(...), array_filter($ids, static fn (mixed $id): bool => \is_int($id) || (\is_string($id) && ctype_digit(trim($id))))));
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return \is_array($value) ? array_values($value) : [];
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
