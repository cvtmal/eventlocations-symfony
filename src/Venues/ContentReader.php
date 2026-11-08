<?php

declare(strict_types=1);

namespace App\Venues;

use App\Shared\Translation\EnabledLocale;
use App\Venues\Exception\InvalidContent;
use App\Venues\ValueObject\Content\DocumentContent;
use App\Venues\ValueObject\Content\PhotoContent;
use App\Venues\ValueObject\Content\RoomContent;
use App\Venues\ValueObject\Content\VenueContent;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads one content folder (`<root>/venues/<key>/`, docs/content-format.md) into a
 * VenueContent, or fails with every problem it found. It never writes.
 *
 * Order: folder name and YAML; the JSON Schema (stops here on errors); the rules a schema
 * cannot express (ranges, main terms, enabled locales, decimals, files on disk, file
 * types); then the file hashes and the content hash.
 */
final class ContentReader
{
    public const array SNIFFED_IMAGE_TYPES = [\IMAGETYPE_JPEG, \IMAGETYPE_PNG, \IMAGETYPE_WEBP];

    private const string NO_LINKS = 'symbolic links are not allowed';

    public function __construct(
        private readonly ContentSchema $schema,
        private readonly EnabledLocale $locales,
    ) {
    }

    /**
     * The folder names under `<contentRoot>/venues/` in byte order; dot entries are skipped,
     * plain files are reported as warnings.
     *
     * @return array{keys: list<string>, warnings: list<string>}
     */
    public function keys(string $contentRoot): array
    {
        $directory = rtrim($contentRoot, '/').'/venues';
        $keys = [];
        $warnings = [];
        foreach (self::entries($directory) as $entry) {
            if (is_dir($directory.'/'.$entry)) {
                $keys[] = $entry;
            } else {
                $warnings[] = \sprintf('venues/%s is a file, not a venue folder; ignored', $entry);
            }
        }

        return ['keys' => $keys, 'warnings' => $warnings];
    }

    /**
     * @throws InvalidContent with every message found
     */
    public function read(string $folder): VenueContent
    {
        $folder = rtrim($folder, '/');
        $key = basename($folder);
        if (!ContentFormat::isKey($key)) {
            throw InvalidContent::withMessages($key, [\sprintf('invalid folder name "%s": use lower-case letters, digits and single hyphens (at most %d characters)', $key, ContentFormat::KEY_MAX_LENGTH)]);
        }
        // Links could pull any file of the host into MEDIA_ROOT and public/media (review F-m1).
        if (is_link($folder)) {
            throw InvalidContent::withMessages($key, ['the venue folder is a symbolic link; '.self::NO_LINKS]);
        }
        $data = $this->parse($key, $folder.'/venue.yaml');

        $errors = $this->schema->validate($data);
        if ([] !== $errors) {
            throw InvalidContent::withMessages($key, $errors);
        }

        $errors = [...$this->ruleErrors($data), ...$this->fileErrors($folder, $data)];
        if ([] !== $errors) {
            throw InvalidContent::withMessages($key, $errors);
        }

        return $this->build($key, $folder, $data);
    }

    private function parse(string $key, string $file): \stdClass
    {
        if (is_link($file)) {
            throw InvalidContent::withMessages($key, ['venue.yaml: '.self::NO_LINKS]);
        }
        if (!is_file($file)) {
            throw InvalidContent::withMessages($key, ['venue.yaml is missing']);
        }
        try {
            $data = Yaml::parseFile($file, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            throw InvalidContent::withMessages($key, ['venue.yaml: '.$exception->getMessage()]);
        }
        if (!$data instanceof \stdClass) {
            throw InvalidContent::withMessages($key, ['venue.yaml: the file must be a mapping of fields (name: …, capacity: …)']);
        }

        return $data;
    }

    /**
     * Cross-field rules on schema-valid data.
     *
     * @return list<string>
     */
    private function ruleErrors(\stdClass $data): array
    {
        $errors = [];

        $errors = [...$errors, ...self::rangeErrors('/capacity', $data->capacity)];

        foreach (get_object_vars($data->main_terms ?? new \stdClass()) as $group => $code) {
            if (!\in_array($code, $data->terms->{$group} ?? [], true)) {
                $errors[] = \sprintf('/main_terms/%s: "%s" is not listed under terms.%s', $group, $code, $group);
            }
        }

        // chk_venues_address would catch these at the flush, without naming the field.
        if (isset($data->address->zip) !== isset($data->address->town)) {
            $errors[] = '/address: zip and town come together';
        }
        if (isset($data->address->street) && !isset($data->address->zip, $data->address->town)) {
            $errors[] = '/address/street: needs zip and town';
        }

        $errors = [...$errors, ...$this->localeErrors('/translations', $data->translations)];
        foreach ($data->photos ?? [] as $i => $photo) {
            $errors = [...$errors, ...$this->localeErrors('/photos/'.$i.'/alt', $photo->alt ?? null)];
        }
        foreach ($data->documents ?? [] as $i => $document) {
            $errors = [...$errors, ...$this->localeErrors('/documents/'.$i.'/title', $document->title)];
        }

        foreach (['station', 'highway', 'airport'] as $distance) {
            if (isset($data->distances->{$distance})) {
                $errors = [...$errors, ...self::decimalErrors('/distances/'.$distance.'/km', $data->distances->{$distance}->km, 1)];
            }
        }

        $roomKeys = [];
        foreach ($data->rooms ?? [] as $i => $room) {
            $pointer = '/rooms/'.$i;
            if (\in_array($room->key, $roomKeys, true)) {
                $errors[] = \sprintf('%s/key: room key "%s" is used twice', $pointer, $room->key);
            }
            $roomKeys[] = $room->key;
            $errors = [...$errors, ...$this->localeErrors($pointer.'/translations', $room->translations)];
            $errors = [...$errors, ...self::rangeErrors($pointer.'/capacity', $room->capacity ?? null)];
            foreach (get_object_vars($room->seating ?? new \stdClass()) as $seating => $range) {
                $errors = [...$errors, ...self::rangeErrors($pointer.'/seating/'.$seating, $range)];
            }
            if (isset($room->height_m)) {
                $errors = [...$errors, ...self::decimalErrors($pointer.'/height_m', $room->height_m, 2)];
            }
            foreach ($room->photos ?? [] as $j => $photo) {
                $errors = [...$errors, ...$this->localeErrors($pointer.'/photos/'.$j.'/alt', $photo->alt ?? null)];
            }
        }

        if ('' === SlugAllocator::base($data->name)) {
            $errors[] = '/name: the name gives an empty URL slug (it needs letters or digits)';
        }

        return $errors;
    }

    /**
     * Every listed file exists and has the right type; nothing unlisted lies in photos/,
     * rooms/<key>/photos/ or documents/; every rooms/<key>/ folder belongs to a room. No
     * sub-folder and no listed file may be a symbolic link (a linked folder is not looked into).
     *
     * @return list<string>
     */
    private function fileErrors(string $folder, \stdClass $data): array
    {
        $errors = [];
        $errors = [...$errors, ...self::listErrors($folder, 'photos', array_values(array_map(static fn (\stdClass $p): string => $p->file, $data->photos ?? [])), true)];

        $roomsLinked = is_link($folder.'/rooms');
        if ($roomsLinked) {
            $errors[] = 'rooms: '.self::NO_LINKS;
        }
        $roomKeys = [];
        foreach ($data->rooms ?? [] as $room) {
            $roomKeys[] = $room->key;
            if ($roomsLinked) {
                continue;
            }
            if (is_link($folder.'/rooms/'.$room->key)) {
                $errors[] = \sprintf('rooms/%s: %s', $room->key, self::NO_LINKS);
                continue;
            }
            $errors = [...$errors, ...self::listErrors($folder, 'rooms/'.$room->key.'/photos', array_values(array_map(static fn (\stdClass $p): string => $p->file, $room->photos ?? [])), true)];
        }
        foreach ($roomsLinked ? [] : self::entries($folder.'/rooms') as $entry) {
            if (!\in_array($entry, $roomKeys, true)) {
                $errors[] = \sprintf('rooms/%s: no room with this key in venue.yaml', $entry);
            }
        }

        $errors = [...$errors, ...self::listErrors($folder, 'documents', array_values(array_map(static fn (\stdClass $d): string => $d->file, $data->documents ?? [])), false)];

        return $errors;
    }

    /**
     * @param list<string> $listed
     *
     * @return list<string>
     */
    private static function listErrors(string $folder, string $directory, array $listed, bool $images): array
    {
        if (is_link($folder.'/'.$directory)) {
            return [\sprintf('%s: %s', $directory, self::NO_LINKS)];
        }
        $errors = [];
        foreach ($listed as $file) {
            $path = $folder.'/'.$directory.'/'.$file;
            if (is_link($path)) {
                $errors[] = \sprintf('%s/%s: %s', $directory, $file, self::NO_LINKS);
            } elseif (!is_file($path)) {
                $errors[] = \sprintf('%s/%s: the file is missing', $directory, $file);
            } elseif ($images) {
                $size = @getimagesize($path);
                if (false === $size || !\in_array($size[2], self::SNIFFED_IMAGE_TYPES, true)) {
                    $errors[] = \sprintf('%s/%s: not a JPEG, PNG or WebP image', $directory, $file);
                }
            } elseif ('%PDF-' !== (string) file_get_contents($path, length: 5)) {
                $errors[] = \sprintf('%s/%s: not a PDF', $directory, $file);
            }
        }
        foreach (self::entries($folder.'/'.$directory) as $entry) {
            if (!\in_array($entry, $listed, true)) {
                $errors[] = \sprintf('%s/%s: not listed in venue.yaml (list it or remove it)', $directory, $entry);
            }
        }

        return $errors;
    }

    private function build(string $key, string $folder, \stdClass $data): VenueContent
    {
        $lines = [];
        $errors = [];
        $photos = self::photos($folder, 'photos', $data->photos ?? [], $lines, $errors);
        $rooms = [];
        foreach ($data->rooms ?? [] as $room) {
            $seating = [];
            foreach (get_object_vars($room->seating ?? new \stdClass()) as $type => $range) {
                $seating[$type] = ['min' => $range->min ?? null, 'max' => $range->max ?? null];
            }
            $translations = [];
            foreach (get_object_vars($room->translations) as $locale => $text) {
                $translations[$locale] = ['name' => $text->name, 'description' => $text->description ?? null];
            }
            $rooms[] = new RoomContent(
                $room->key,
                $translations,
                $room->capacity->min ?? null,
                $room->capacity->max ?? null,
                $room->area_m2 ?? null,
                isset($room->height_m) ? \sprintf('%.2f', $room->height_m) : null,
                isset($room->floor_material) ? FloorMaterial::from($room->floor_material) : null,
                $seating,
                self::photos($folder, 'rooms/'.$room->key.'/photos', $room->photos ?? [], $lines, $errors),
            );
        }
        $documents = [];
        $hashes = [];
        foreach ($data->documents ?? [] as $document) {
            $path = $folder.'/documents/'.$document->file;
            $sha256 = (string) hash_file('sha256', $path);
            if (isset($hashes[$sha256])) {
                $errors[] = \sprintf('documents/%s: the same file as documents/%s', $document->file, $hashes[$sha256]);
            }
            $hashes[$sha256] = $document->file;
            $lines[] = 'documents/'.$document->file.' '.$sha256;
            $documents[] = new DocumentContent($document->file, $path, $sha256, (int) filesize($path), self::strings($document->title));
        }
        if ([] !== $errors) {
            throw InvalidContent::withMessages($key, $errors);
        }

        $translations = [];
        foreach (get_object_vars($data->translations) as $locale => $text) {
            $translations[$locale] = [
                'summary' => $text->summary,
                'description' => $text->description ?? null,
                'metaDescription' => $text->meta_description ?? null,
                'parkingText' => $text->parking_text ?? null,
                'ambience' => $text->ambience ?? null,
                'capacityNote' => $text->capacity_note ?? null,
                'openingHours' => $text->opening_hours ?? null,
                'region' => $text->region ?? null,
            ];
        }
        $terms = [];
        foreach (get_object_vars($data->terms ?? new \stdClass()) as $group => $codes) {
            $terms[$group] = array_values($codes);
        }
        $distance = static fn (string $name): ?\stdClass => $data->distances->{$name} ?? null;

        return new VenueContent(
            key: $key,
            contentHash: hash('sha256', ContentFormat::VERSION."\n".self::canonicalJson($data)."\n".implode("\n", $lines)),
            name: $data->name,
            status: VenueStatus::from($data->status ?? VenueStatus::Published->value),
            capacityMax: $data->capacity->max,
            capacityMin: $data->capacity->min ?? null,
            street: $data->address->street ?? null,
            zip: $data->address->zip ?? null,
            townName: $data->address->town ?? null,
            cantonCode: $data->address->canton ?? null,
            lat: isset($data->geo) ? (float) $data->geo->lat : null,
            lng: isset($data->geo) ? (float) $data->geo->lng : null,
            stationKm: self::decimal($distance('station')?->km, 1),
            stationName: $distance('station')->name ?? null,
            highwayKm: self::decimal($distance('highway')?->km, 1),
            highwayName: $distance('highway')->name ?? null,
            airportKm: self::decimal($distance('airport')?->km, 1),
            airportName: $distance('airport')->name ?? null,
            phone: $data->contact->phone ?? null,
            inquiryEmail: $data->contact->inquiry_email ?? null,
            website: $data->contact->website ?? null,
            contactPerson: $data->contact->contact_person ?? null,
            ownCatering: isset($data->faq->own_catering) ? OwnCatering::from($data->faq->own_catering) : null,
            daylight: $data->faq->daylight ?? null,
            priceLevel: isset($data->price_level) ? PriceLevel::from($data->price_level) : null,
            capacitySeated: $data->capacity->seated ?? null,
            capacityStanding: $data->capacity->standing ?? null,
            instagram: $data->contact->instagram ?? null,
            terms: $terms,
            mainTerms: self::strings($data->main_terms ?? null),
            translations: $translations,
            photos: $photos,
            rooms: $rooms,
            documents: $documents,
        );
    }

    /**
     * Hashes the photos of one list (appending "<path> <sha256>" lines) and reports a file
     * listed twice by content.
     *
     * @param list<\stdClass> $photos
     * @param list<string>    $lines
     * @param list<string>    $errors
     *
     * @return list<PhotoContent>
     */
    private static function photos(string $folder, string $directory, array $photos, array &$lines, array &$errors): array
    {
        $result = [];
        $hashes = [];
        foreach ($photos as $photo) {
            $path = $folder.'/'.$directory.'/'.$photo->file;
            $sha256 = (string) hash_file('sha256', $path);
            if (isset($hashes[$sha256])) {
                $errors[] = \sprintf('%s/%s: the same image as %s/%s', $directory, $photo->file, $directory, $hashes[$sha256]);
            }
            $hashes[$sha256] = $photo->file;
            $lines[] = $directory.'/'.$photo->file.' '.$sha256;
            $extension = strtolower(pathinfo($photo->file, \PATHINFO_EXTENSION));
            $result[] = new PhotoContent($photo->file, $path, $sha256, 'jpeg' === $extension ? 'jpg' : $extension, self::strings($photo->alt ?? null));
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function localeErrors(string $pointer, ?\stdClass $map): array
    {
        $errors = [];
        foreach (array_keys(get_object_vars($map ?? new \stdClass())) as $locale) {
            if (!$this->locales->isEnabled((string) $locale)) {
                $errors[] = \sprintf('%s/%s: locale "%s" is not enabled (enabled: %s)', $pointer, $locale, $locale, implode(', ', $this->locales->all()));
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function rangeErrors(string $pointer, ?\stdClass $range): array
    {
        if (isset($range->min, $range->max) && $range->min > $range->max) {
            return [\sprintf('%s: min (%d) is greater than max (%d)', $pointer, $range->min, $range->max)];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function decimalErrors(string $pointer, int|float $value, int $decimals): array
    {
        if (abs(round($value, $decimals) - $value) > 1e-9) {
            return [\sprintf('%s: %s has more than %d decimal%s', $pointer, $value, $decimals, 1 === $decimals ? '' : 's')];
        }

        return [];
    }

    private static function decimal(int|float|null $value, int $decimals): ?string
    {
        return null === $value ? null : \sprintf('%.'.$decimals.'f', $value);
    }

    /**
     * @return array<string, string>
     */
    private static function strings(?\stdClass $map): array
    {
        /** @var array<string, string> $strings */
        $strings = get_object_vars($map ?? new \stdClass());

        return $strings;
    }

    /**
     * The data as JSON with object keys sorted at every level; list order is kept.
     */
    private static function canonicalJson(\stdClass $data): string
    {
        return json_encode(self::sorted($data), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);
    }

    private static function sorted(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, \SORT_STRING);

            return (object) array_map(self::sorted(...), $properties);
        }
        if (\is_array($value)) {
            return array_map(self::sorted(...), $value);
        }

        return $value;
    }

    /**
     * Directory entries in byte order without dot entries; empty when the directory is missing.
     *
     * @return list<string>
     */
    private static function entries(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $entries = array_values(array_filter(scandir($directory) ?: [], static fn (string $entry): bool => !str_starts_with($entry, '.')));
        usort($entries, strcmp(...));

        return $entries;
    }
}
