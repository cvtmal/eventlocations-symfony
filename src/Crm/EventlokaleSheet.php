<?php

declare(strict_types=1);

namespace App\Crm;

use App\Venues\Sheet\VenueSheet;

/**
 * The crawl's draft sheet (F14, D32): `owner/eventlokale-entwuerfe.csv` in the venue sheet's columns
 * (`VenueSheet::HEADER`), one row per venue whose detail was fetched, with `source` eventlokale and the facts
 * only: name, street and number, zip, town, coordinates, capacity, phone, inquiry e-mail and website. Texts,
 * photos, ratings, likes, awards, highlights, terms and contact persons are never copied (D11, D12: crawled facts
 * are never published as they are, and eventlokale's texts and photos never reach the site). An address on the
 * suppression list leaves the e-mail cell empty (G11), as in EventlokaleCsv.
 *
 * The owner reviews the file (deletes rows, adds event types and a building), then runs
 * app:venues:convert-sheet on it; every row becomes a draft. Written in CsvStream's dialect (`;`, BOM, formula
 * guard), which VenueSheet::read() takes back.
 */
final class EventlokaleSheet
{
    public const string FILE = 'owner/eventlokale-entwuerfe.csv';

    public const string SOURCE = 'eventlokale';

    /**
     * @param list<string> $suppressed the suppression list's addresses (`SuppressionQuery::addresses()`)
     *
     * @return int the rows written
     */
    public function write(string $root, array $suppressed = []): int
    {
        $cache = new EventlokaleCache($root);
        $suppressed = array_flip($suppressed);
        $items = $cache->readIndex()['items'];
        usort($items, static fn (array $a, array $b): int => (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));

        $rows = [];
        foreach ($items as $item) {
            $detail = \is_int($item['id'] ?? null) ? $cache->readDetail($item['id']) : null;
            if (null !== $detail) {
                $rows[] = self::row($item, $detail, $suppressed);
            }
        }

        $stream = fopen('php://temp', 'w+');
        if (false === $stream) {
            throw new \RuntimeException('Cannot open a temporary stream.');
        }
        CsvStream::write($stream, VenueSheet::HEADER, $rows);
        rewind($stream);
        $cache->writeFile(self::FILE, (string) stream_get_contents($stream));
        fclose($stream);

        return \count($rows);
    }

    /**
     * @param array<mixed>      $item
     * @param array<mixed>      $detail
     * @param array<string,int> $suppressed address => any
     *
     * @return list<string|null> in VenueSheet::HEADER order
     */
    private static function row(array $item, array $detail, array $suppressed): array
    {
        $email = EventlokaleCsv::clean(EventlokaleCsv::at($detail, 'owner.email'));
        if (null !== $email && isset($suppressed[mb_strtolower($email)])) {
            $email = null;
        }
        $street = trim(implode(' ', array_filter([
            EventlokaleCsv::clean(EventlokaleCsv::at($detail, 'address.street')),
            EventlokaleCsv::clean(EventlokaleCsv::at($detail, 'address.street_number')),
        ])));

        $cells = [
            'source' => self::SOURCE,
            'name' => EventlokaleCsv::clean(EventlokaleCsv::at($detail, 'general_data.full_name')) ?? EventlokaleCsv::clean($item['name'] ?? null),
            'street' => '' === $street ? null : $street,
            'zip' => EventlokaleCsv::clean(EventlokaleCsv::at($detail, 'address.zip')) ?? EventlokaleCsv::clean($item['zip'] ?? null),
            'town' => EventlokaleCsv::clean(EventlokaleCsv::at($detail, 'address.place')) ?? EventlokaleCsv::clean($item['place'] ?? null),
            'lat' => EventlokaleCsv::clean($item['lat'] ?? null),
            'lng' => EventlokaleCsv::clean($item['lng'] ?? null),
            'capacity_min' => EventlokaleCsv::clean($item['min_capacity'] ?? null),
            'capacity_max' => EventlokaleCsv::clean($item['max_capacity'] ?? null),
            'phone' => EventlokaleCsv::clean(EventlokaleCsv::at($detail, 'owner.phone')),
            'inquiry_email' => $email,
            'website' => self::website(EventlokaleCsv::clean(EventlokaleCsv::at($detail, 'owner.website')) ?? EventlokaleCsv::clean(EventlokaleCsv::at($detail, 'general_data.website'))),
        ];

        return array_map(static fn (string $column): ?string => $cells[$column] ?? null, VenueSheet::HEADER);
    }

    /** The content format wants a scheme; `www.example.ch` becomes `https://www.example.ch`. */
    private static function website(?string $url): ?string
    {
        if (null === $url || 1 === preg_match('~\Ahttps?://~i', $url)) {
            return $url;
        }

        return 'https://'.$url;
    }
}
