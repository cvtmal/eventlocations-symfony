<?php

declare(strict_types=1);

namespace App\Venues\Sheet;

use App\Shared\Csv\SheetFile;
use App\Venues\ContentFormat;
use App\Venues\ContentSchema;
use Psr\Clock\ClockInterface;

/**
 * The venue sheet (F14, docs/content-format.md "Venue sheet"): one venue per row, the facts a spreadsheet holds,
 * converted into draft content folders by `app:venues:convert-sheet` and then imported as usual. The crawler's
 * draft sheet (`Crm\EventlokaleSheet`) writes the same columns.
 *
 * read() takes the CSV as `SheetFile` reads it (`,` or `;`, byte order mark, columns by name, a missing or unknown
 * column refuses the file). check() applies the row rules
 * (source, key, texts, consent) and then the content schema to the document the row builds.
 */
final class VenueSheet
{
    public const array HEADER = [
        'key', 'source', 'name', 'street', 'zip', 'town', 'canton', 'region', 'lat', 'lng',
        'capacity_min', 'capacity_max', 'capacity_seated', 'capacity_standing', 'price_level', 'event_types', 'building',
        'phone', 'inquiry_email', 'website', 'contact_person', 'instagram', 'summary', 'description', 'consent_on', 'consent_from',
    ];

    /** Where a row comes from (D1): the owner's own list, a consenting hochzeitslocation.ch customer (D29), the crawl. */
    public const array SOURCES = ['owner', 'hochzeitslocation', 'eventlokale'];

    public const string SOURCE_CONSENT = 'hochzeitslocation';

    /** The only source whose texts may come in: the owner's own (D1(c), D11, D12: a text of another site is never copied). */
    public const string SOURCE_OWN_TEXTS = 'owner';

    /** @var list<string> */
    private const array TEXT_COLUMNS = ['summary', 'description'];

    public function __construct(
        private readonly ContentSchema $schema,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<VenueSheetRow>
     *
     * @throws \RuntimeException when the file is missing or its header is not the sheet's
     */
    public static function read(string $file): array
    {
        return array_map(
            static fn (array $row): VenueSheetRow => new VenueSheetRow($row['line'], $row['cells']),
            SheetFile::read($file, self::HEADER, 'venue sheet'),
        );
    }

    /**
     * @return list<string> "row N: <problem>"; empty when the row converts
     */
    public function check(VenueSheetRow $row): array
    {
        $errors = [];
        if (!\in_array($row->source(), self::SOURCES, true)) {
            $errors[] = \sprintf('source must be one of %s', implode(', ', self::SOURCES));
        }
        $key = $row->key();
        if (!ContentFormat::isKey($key)) {
            $errors[] = $row->keyIsDerived()
                ? 'key: cannot be made from name and town; write one'
                : 'key: lower-case letters, digits and single hyphens, at most '.ContentFormat::KEY_MAX_LENGTH.' characters';
        }
        if ('' === $row->cell('summary') && ('' !== $row->cell('description') || '' !== $row->cell('region'))) {
            $errors[] = 'summary: needed when description or region is written';
        }
        if (self::SOURCE_OWN_TEXTS !== $row->source()) {
            foreach (self::TEXT_COLUMNS as $column) {
                if ('' !== $row->cell($column)) {
                    $errors[] = \sprintf('%s: only for source %s, a text is never copied', $column, self::SOURCE_OWN_TEXTS);
                }
            }
        }
        $errors = [...$errors, ...$this->consentErrors($row)];
        foreach ($this->schema->validate($row->toDocument()) as $error) {
            $errors[] = $error;
        }

        return array_map(static fn (string $error): string => \sprintf('row %d: %s', $row->line, $error), $errors);
    }

    /**
     * D29: a hochzeitslocation.ch customer is imported only after a yes, so the row names when and from which
     * address it came; the other sources carry no consent.
     *
     * @return list<string>
     */
    private function consentErrors(VenueSheetRow $row): array
    {
        $on = $row->cell('consent_on');
        $from = $row->cell('consent_from');
        if (self::SOURCE_CONSENT !== $row->source()) {
            return '' === $on && '' === $from ? [] : ['consent_on, consent_from: only for source '.self::SOURCE_CONSENT];
        }

        $errors = [];
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $on, new \DateTimeZone('Europe/Zurich'));
        if (false === $date || $date->format('Y-m-d') !== $on) {
            $errors[] = 'consent_on: the date of the yes (YYYY-MM-DD) is required';
        } elseif ($date > $this->clock->now()) {
            $errors[] = 'consent_on: lies in the future';
        }
        if (false === filter_var($from, \FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'consent_from: the address the yes came from is required';
        }

        return $errors;
    }
}
