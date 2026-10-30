<?php

declare(strict_types=1);

namespace App\Tests\Support\Content;

/**
 * Builds a fictional Wix CSV at runtime, in the shape of the owner's export: a BOM, the 20
 * columns, line breaks inside fields, rich-text JSON. The repo keeps no real row: every name,
 * number and address here is invented.
 */
final class WixCsv
{
    public const array COLUMNS = [
        'Name', 'Anzeigebild', 'Typ', 'Region', 'Kapazität', 'Kapazität-Filter', 'Ambiente', 'Preisniveau', 'Vorstellungstext', 'Öffnungszeiten',
        'Telefon', 'Website', 'Email', 'Locations Startseite (Item)', 'Locations Startseite (List)', 'ID', 'Created Date', 'Updated Date', 'Owner', 'Team (All)',
    ];

    public const string PHONE = '0441234567';
    public const string PHONE_E164 = '+41441234567';
    public const string EMAIL = 'kontakt@beispielhof.example.ch';
    public const string WEBSITE = 'https://beispielhof.example.ch';
    public const string ID = '1a2b3c4d-0000-4000-8000-000000000001';
    public const string DESCRIPTION = 'Der fiktive Beispielhof liegt zwischen zwei erfundenen Hügeln und bietet Platz für ein Fest. Die Scheune wurde für diesen Test frei erfunden.';

    /**
     * One valid row of a venue in canton Zürich, with $overrides.
     *
     * @param array<string, string> $overrides by column
     *
     * @return array<string, string>
     */
    public static function row(array $overrides = []): array
    {
        return $overrides + [
            'Name' => 'Landgasthof Beispielhof',
            'Anzeigebild' => 'wix:image://v1/fiktiv.jpg',
            'Typ' => 'Scheune',
            'Region' => 'Zürich',
            'Kapazität' => '120 Personen',
            'Kapazität-Filter' => '120',
            'Ambiente' => 'Ländlich',
            'Preisniveau' => '$$',
            'Vorstellungstext' => self::richText(self::paragraph('Beschrieb:'), self::paragraph(self::DESCRIPTION), self::paragraph('Kapazität:'), self::paragraph('120 Gäste')),
            'Öffnungszeiten' => '',
            'Telefon' => self::PHONE,
            'Website' => self::WEBSITE,
            'Email' => self::EMAIL,
            'Locations Startseite (Item)' => '',
            'Locations Startseite (List)' => '',
            'ID' => self::ID,
            'Created Date' => '2024-01-01T00:00:00Z',
            'Updated Date' => '2024-01-02T00:00:00Z',
            'Owner' => 'owner-1',
            'Team (All)' => '',
        ];
    }

    /**
     * Writes the file: the BOM, a header of $columns, the rows.
     *
     * @param list<array<string, string>> $rows
     * @param list<string>                $columns
     */
    public static function write(string $path, array $rows, array $columns = self::COLUMNS): void
    {
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0777, true);
        }
        $handle = fopen($path, 'w');
        \assert(false !== $handle);
        // Quoted like the owner's file: the BOM sits right in front of the first quote.
        fwrite($handle, "\u{FEFF}\"".implode('","', $columns)."\"\n");
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn (string $column): string => $row[$column] ?? '', $columns), ',', '"', '');
        }
        fclose($handle);
    }

    /**
     * @param array<string, mixed> ...$nodes
     */
    public static function richText(array ...$nodes): string
    {
        return json_encode(['nodes' => array_values($nodes), 'documentStyle' => new \stdClass()], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public static function paragraph(string $text): array
    {
        return ['type' => 'PARAGRAPH', 'nodes' => [self::text($text)]];
    }

    /**
     * An empty paragraph, as Wix leaves between blocks.
     *
     * @return array<string, mixed>
     */
    public static function emptyParagraph(): array
    {
        return ['type' => 'PARAGRAPH', 'nodes' => []];
    }

    /**
     * @param array<string, mixed>|string ...$items a plain item, or a list() nested in the item before it
     *
     * @return array<string, mixed>
     */
    public static function list(array|string ...$items): array
    {
        $listItems = [];
        foreach ($items as $item) {
            if (\is_string($item)) {
                $listItems[] = ['type' => 'LIST_ITEM', 'nodes' => [self::paragraph($item)]];
            } else {
                $last = array_key_last($listItems);
                \assert(null !== $last);
                $listItems[$last]['nodes'][] = $item;
            }
        }

        return ['type' => 'BULLETED_LIST', 'nodes' => $listItems];
    }

    /**
     * @return array<string, mixed>
     */
    public static function image(string $caption): array
    {
        return ['type' => 'IMAGE', 'nodes' => [['type' => 'CAPTION', 'nodes' => [self::text($caption)]]]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function text(string $text): array
    {
        return ['type' => 'TEXT', 'nodes' => [], 'textData' => ['text' => $text, 'decorations' => []]];
    }
}
