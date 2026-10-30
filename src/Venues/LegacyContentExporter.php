<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Exception\InvalidWixExport;
use App\Venues\ValueObject\Content\LegacyExportReport;
use App\Venues\ValueObject\Content\LegacyVenue;

/**
 * Turns the owner's CSV into content folders for app:venues:import (#21). It writes files
 * only, so it needs no database and no bus; #9's import stays the one write path (S8).
 *
 * A file that cannot be read writes nothing, because every row is read before the first
 * write. A row that cannot be mapped is reported and the others are still written. No folder
 * is removed: one the CSV no longer has is reported as stale.
 */
final class LegacyContentExporter
{
    private const string FOLDER_WITH_ID = '/-([0-9a-f]{8})$/D';

    public function __construct(
        private readonly ContentReader $reader,
        private readonly LegacyVenueMapper $mapper,
        private readonly LegacyFolderWriter $writer,
    ) {
    }

    /**
     * @throws InvalidWixExport when the file itself is unusable
     */
    public function export(string $csv, string $root, bool $dryRun): LegacyExportReport
    {
        $rows = iterator_to_array(WixExport::rows($csv));
        $existing = is_dir($root.'/venues') ? $this->reader->keys($root)['keys'] : [];

        $folders = [];
        foreach ($existing as $key) {
            if (1 === preg_match(self::FOLDER_WITH_ID, $key, $match)) {
                $folders[$match[1]][] = $key;
            }
        }

        $counts = array_fill_keys(['created', 'updated', 'unchanged', 'skipped', 'canton', 'region', 'phoneDropped', 'phoneEmpty', 'emailDropped', 'websiteDropped', 'rule1', 'rule2', 'rule3', 'rule4', 'sentence', 'word', 'whole', 'ambience'], 0);
        $counts['rows'] = \count($rows);
        $regions = [];
        $cantons = [];
        $notes = [];
        $unresolved = [];
        $exported = [];

        foreach ($rows as $number => $row) {
            try {
                $venue = $this->mapper->map($row);
                if ($venue->skipped) {
                    ++$counts['skipped'];
                    $notes[] = \sprintf('row %d: skipped, not in Switzerland', $number);
                    continue;
                }
                if (isset($exported[$venue->id8])) {
                    throw InvalidWixExport::unresolvedRow('another row has the same first 8 characters of the ID');
                }
                $found = $folders[$venue->id8] ?? [];
                if (\count($found) > 1) {
                    throw InvalidWixExport::unresolvedRow('two folders end in the same ID');
                }
                $key = $found[0] ?? LegacyVenueText::key($venue->name, $venue->id8);
                $outcome = $this->writer->write($root.'/venues/'.$key, $venue, $dryRun);
            } catch (InvalidWixExport $exception) {
                $unresolved[] = \sprintf('row %d: %s', $number, $exception->getMessage());
                continue;
            }

            $exported[$venue->id8] = true;
            ++$counts[$outcome];
            self::tally($venue, $counts, $regions, $cantons);
            foreach ($venue->notes as $note) {
                $notes[] = $key.': '.$note;
            }
        }

        foreach ($existing as $key) {
            if (1 !== preg_match(self::FOLDER_WITH_ID, $key, $match) || !isset($exported[$match[1]])) {
                $notes[] = $key.': stale, not in the CSV; left alone';
            }
        }
        arsort($regions);

        return new LegacyExportReport($dryRun, $counts, $regions, array_keys($cantons), $notes, $unresolved);
    }

    /**
     * @param array<string, int>  $counts
     * @param array<string, int>  $regions
     * @param array<string, true> $cantons
     */
    private static function tally(LegacyVenue $venue, array &$counts, array &$regions, array &$cantons): void
    {
        if (null !== $venue->cantonCode) {
            ++$counts['canton'];
            $cantons[$venue->cantonCode] = true;
        }
        if (null !== $venue->region) {
            ++$counts['region'];
            $regions[$venue->region] = ($regions[$venue->region] ?? 0) + 1;
        }
        $phoneDropped = \in_array(LegacyVenueMapper::NOTE_PHONE_DROPPED, $venue->notes, true);
        $counts['phoneDropped'] += (int) $phoneDropped;
        $counts['phoneEmpty'] += (int) (null === $venue->phone && !$phoneDropped);
        $counts['emailDropped'] += (int) \in_array(LegacyVenueMapper::NOTE_EMAIL_DROPPED, $venue->notes, true);
        $counts['websiteDropped'] += (int) \in_array(LegacyVenueMapper::NOTE_WEBSITE_DROPPED, $venue->notes, true);
        ++$counts['rule'.$venue->descriptionRule];
        ++$counts[$venue->summarySource];
    }
}
