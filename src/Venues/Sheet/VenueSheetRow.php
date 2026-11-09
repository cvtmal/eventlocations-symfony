<?php

declare(strict_types=1);

namespace App\Venues\Sheet;

use App\Venues\ContentFormat;
use App\Venues\SlugAllocator;
use App\Venues\ValueObject\VenueStatus;

/**
 * One row of the venue sheet (F14, {@see VenueSheet}): the cells as written, trimmed, by column name. toDocument()
 * builds the venue.yaml document of a draft (`status: unpublished`) from the facts; the texts go in only when the
 * row has a summary. A cell that is not a number where one is expected goes in as written, so the schema names it.
 */
final readonly class VenueSheetRow
{
    /**
     * @param int                   $line  the row's line in the file, the header being line 1
     * @param array<string, string> $cells by column of VenueSheet::HEADER
     */
    public function __construct(
        public int $line,
        private array $cells,
    ) {
    }

    public function cell(string $column): string
    {
        return $this->cells[$column] ?? '';
    }

    public function source(): string
    {
        return $this->cell('source');
    }

    /**
     * The key the row names, else `kebab(name)-kebab(town)` cut at a hyphen to the key's length; '' when neither
     * gives letters or digits.
     */
    public function key(): string
    {
        if ('' !== $this->cell('key')) {
            return $this->cell('key');
        }
        $key = SlugAllocator::base(trim($this->cell('name').' '.$this->cell('town')));
        if (\strlen($key) > ContentFormat::KEY_MAX_LENGTH) {
            $key = substr($key, 0, ContentFormat::KEY_MAX_LENGTH + 1);
            $key = substr($key, 0, (int) strrpos($key, '-'));
        }

        return $key;
    }

    /**
     * The `suitable_for` codes of the `event_types` cell (`|`-separated), each once.
     *
     * @return list<string>
     */
    public function eventTypes(): array
    {
        return array_values(array_unique(array_filter(array_map(trim(...), explode('|', $this->cell('event_types'))), static fn (string $code): bool => '' !== $code)));
    }

    public function keyIsDerived(): bool
    {
        return '' === $this->cell('key');
    }

    public function toDocument(): \stdClass
    {
        $document = new \stdClass();
        $document->name = $this->cell('name');
        $document->status = VenueStatus::Unpublished->value;
        if ('' !== $this->cell('price_level')) {
            $document->price_level = $this->cell('price_level');
        }

        $capacity = new \stdClass();
        foreach (['min', 'max', 'seated', 'standing'] as $field) {
            if ('' !== $this->cell('capacity_'.$field)) {
                $capacity->{$field} = self::integer($this->cell('capacity_'.$field));
            }
        }
        $document->capacity = $capacity;

        $address = self::object(['street' => $this->cell('street'), 'zip' => $this->cell('zip'), 'town' => $this->cell('town'), 'canton' => strtolower($this->cell('canton'))]);
        if (null !== $address) {
            $document->address = $address;
        }
        if ('' !== $this->cell('lat') || '' !== $this->cell('lng')) {
            $geo = new \stdClass();
            foreach (['lat', 'lng'] as $field) {
                if ('' !== $this->cell($field)) {
                    $geo->{$field} = is_numeric($this->cell($field)) ? (float) $this->cell($field) : $this->cell($field);
                }
            }
            $document->geo = $geo;
        }

        $contact = self::object([
            'phone' => self::phone($this->cell('phone')),
            'inquiry_email' => $this->cell('inquiry_email'),
            'website' => $this->cell('website'),
            'contact_person' => $this->cell('contact_person'),
            'instagram' => $this->cell('instagram'),
        ]);
        if (null !== $contact) {
            $document->contact = $contact;
        }

        $terms = new \stdClass();
        $eventTypes = $this->eventTypes();
        if ([] !== $eventTypes) {
            $terms->suitable_for = $eventTypes;
        }
        if ('' !== $this->cell('building')) {
            $terms->building = [$this->cell('building')];
            $document->main_terms = (object) ['building' => $this->cell('building')];
        }
        if ([] !== get_object_vars($terms)) {
            $document->terms = $terms;
        }

        $texts = self::object(['summary' => $this->cell('summary'), 'description' => $this->cell('description'), 'region' => $this->cell('region')]);
        if (null !== $texts) {
            $document->translations = (object) ['de' => $texts];
        }

        return $document;
    }

    /**
     * A Swiss national number (`044 123 45 67`) becomes `+41…` and `00` becomes `+`, as the CMS field reads it;
     * anything else stays as written for the schema to judge.
     */
    private static function phone(string $typed): string
    {
        if ('' === $typed) {
            return '';
        }
        $number = str_replace(['(0)', ' ', '.', '-', '/', '(', ')'], '', $typed);
        if (str_starts_with($number, '00')) {
            $number = '+'.substr($number, 2);
        } elseif (str_starts_with($number, '0')) {
            $number = '+41'.substr($number, 1);
        }

        return 1 === preg_match('/\A\+[1-9][0-9]{6,14}\z/', $number) ? $number : $typed;
    }

    private static function integer(string $cell): int|string
    {
        return 1 === preg_match('/\A[0-9]{1,9}\z/', $cell) ? (int) $cell : $cell;
    }

    /**
     * @param array<string, string> $fields
     */
    private static function object(array $fields): ?\stdClass
    {
        $fields = array_filter($fields, static fn (string $value): bool => '' !== $value);

        return [] === $fields ? null : (object) $fields;
    }
}
