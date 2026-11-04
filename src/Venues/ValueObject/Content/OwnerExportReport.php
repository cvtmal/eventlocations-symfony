<?php

declare(strict_types=1);

namespace App\Venues\ValueObject\Content;

/**
 * What app:owner:export-content did or, in a dry run, would do (#32). lines() is for the
 * console and holds counts only; with $notes and $unresolved (`<key>: <reason>`,
 * `row <n>: <reason>`) that is all the console shows. markdown() is the report file under
 * CONTENT_ROOT/owner/, git-ignored, and also lists the cells no table maps, so the owner can
 * see them.
 */
final readonly class OwnerExportReport
{
    public const string SPOT_CHECK_TITLE = 'civil_ceremony from "Räumlichkeiten für standesamtliche Hochzeiten" (owner spot-check)';

    /**
     * @param array<string, int>                $counts          counters by name, see the keys in lines()
     * @param list<string>                      $notes           `<key>: <reason>`, also stale folders
     * @param list<string>                      $unresolved      `row <n>: <reason>`
     * @param array<string, array<string, int>> $unmapped        folded section label to answer to the number of venues
     * @param list<string>                      $photosLeftOut   `<key>: photo <n> (<id>): <reason>`, report file only
     * @param list<string>                      $spotCheck       keys whose civil_ceremony came from the older form (D7)
     * @param list<string>                      $withoutSeated   keys without a seated number
     * @param list<string>                      $withoutStanding keys without a standing number
     */
    public function __construct(
        public bool $dryRun,
        public array $counts,
        public array $notes,
        public array $unresolved,
        public array $unmapped,
        public array $photosLeftOut,
        public array $spotCheck,
        public array $withoutSeated,
        public array $withoutStanding,
    ) {
    }

    /**
     * @return list<string> the summary, one line per subject
     */
    public function lines(): array
    {
        $c = $this->counts;

        return [
            \sprintf('%sRows: %d. Overlaid: %d (%d updated, %d unchanged). Unresolved: %d.', $this->dryRun ? 'Dry run: ' : '', $c['rows'], $c['updated'] + $c['unchanged'], $c['updated'], $c['unchanged'], \count($this->unresolved)),
            \sprintf('Photos: %d listed, %d cached, %d downloaded, %d would download, %d failed to download, %d left out.', $c['photos'], $c['cached'], $c['downloaded'], $c['wouldDownload'], $c['failed'], $c['leftOut']),
            \sprintf('Facts: %d price level, %d seated, %d standing, %d party, %d no curfew, %d curfew time, %d own catering.', $c['priceLevel'], $c['seated'], $c['standing'], $c['party'], $c['noCurfew'], $c['curfewAt'], $c['ownCatering']),
            \sprintf('Notes: %d capacity, %d party, %d opening hours, %d parking. Spot-check: %d civil_ceremony from the older form.', $c['capacityNote'], $c['partyNote'], $c['openingHours'], $c['parkingText'], \count($this->spotCheck)),
            \sprintf('Unmapped answers: %d distinct in %d sections (details in the report file).', array_sum(array_map(\count(...), $this->unmapped)), \count($this->unmapped)),
        ];
    }

    public function markdown(): string
    {
        $out = ['# Owner content export'.($this->dryRun ? ' (dry run)' : ''), ''];
        foreach ($this->lines() as $line) {
            $out[] = '- '.$line;
        }

        $out = [...$out, '', '## Unmapped answers', '', 'By section, the answers no table maps, with the number of venues.', ''];
        foreach ($this->unmapped as $section => $answers) {
            $out[] = '### '.$section;
            $out[] = '';
            foreach ($answers as $answer => $count) {
                $out[] = \sprintf('- %d × %s', $count, self::inline((string) $answer));
            }
            $out[] = '';
        }

        $out = [...$out, ...self::section('Photos left out', $this->photosLeftOut)];
        $out = [...$out, ...self::section('Notes', $this->notes)];
        $out = [...$out, ...self::section('Without a seated number', $this->withoutSeated)];
        $out = [...$out, ...self::section('Without a standing number', $this->withoutStanding)];
        $out = [...$out, ...self::section('Unresolved rows', $this->unresolved)];
        $out = [...$out, ...self::section(self::SPOT_CHECK_TITLE, $this->spotCheck, 'These venues get "Zivile Trauung möglich" from the older form\'s option. To disagree, remove the marked row in OwnerFactsMapper and run the export and the import again.')];

        return implode("\n", $out);
    }

    /**
     * @param list<string> $items
     *
     * @return list<string>
     */
    private static function section(string $title, array $items, ?string $intro = null): array
    {
        $out = ['## '.$title, ''];
        if (null !== $intro) {
            $out = [...$out, $intro, ''];
        }
        if ([] === $items) {
            return [...$out, 'None.', ''];
        }
        foreach ($items as $item) {
            $out[] = '- '.self::inline($item);
        }

        return [...$out, ''];
    }

    /**
     * One line of Markdown: a line break in an answer would start a new item.
     */
    private static function inline(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
