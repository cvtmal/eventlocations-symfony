<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Exception\InvalidWixExport;
use App\Venues\ValueObject\Content\OwnerExportReport;
use App\Venues\ValueObject\Content\OwnerFacts;
use App\Venues\ValueObject\Content\OwnerPhoto;

/**
 * Overlays the owner's S14 facts and Wix photos on the folders #21's export wrote (#32). The
 * CSV and its Wix image links are the only source (S14): nothing is looked up elsewhere and
 * nothing is inferred. It writes files only, so app:venues:import stays the one write path
 * (S8). Run #21's export first, then this one, then the import.
 *
 * Every row is read before the first write, so a file that cannot be read changes nothing.
 * A row without a #21 folder is unresolved (D2) and the others are still written. A photo the
 * import would refuse is left out and reported, never the venue (D10, D11); a failed
 * download leaves the photo out too, and the next run fills the gap from the cache (D13).
 */
final class OwnerContentExporter
{
    public const string REPORT_FILE = 'owner/export-report.md';
    public const string PHOTO_CACHE = 'owner/photos';

    public const string LEFT_OUT_BAD_ID = 'not a Wix media id';
    public const string LEFT_OUT_AVIF = 'an AVIF image, which the import refuses';
    public const string LEFT_OUT_DOWNLOAD = 'the download failed; the next run tries again';

    private const array COUNTERS = ['updated', 'unchanged', 'photos', 'cached', 'downloaded', 'wouldDownload', 'failed', 'leftOut', 'priceLevel', 'seated', 'standing', 'party', 'noCurfew', 'curfewAt', 'ownCatering', 'capacityNote', 'partyNote', 'openingHours', 'parkingText'];

    public function __construct(
        private readonly ContentReader $reader,
        private readonly OwnerFactsMapper $mapper,
        private readonly OwnerFolderWriter $writer,
        private readonly WixPhotoCache $cache,
    ) {
    }

    /**
     * @throws InvalidWixExport when the file itself is unusable
     */
    public function export(string $csv, string $root, bool $dryRun): OwnerExportReport
    {
        $rows = iterator_to_array(WixExport::rows($csv, WixExport::OWNER_COLUMNS));
        $existing = is_dir($root.'/venues') ? $this->reader->keys($root)['keys'] : [];
        $cacheDir = $root.'/'.self::PHOTO_CACHE;

        $folders = [];
        foreach ($existing as $key) {
            $id8 = LegacyVenueText::folderId($key);
            if (null !== $id8) {
                $folders[$id8][] = $key;
            }
        }

        $counts = array_fill_keys(self::COUNTERS, 0);
        $counts['rows'] = \count($rows);
        $notes = [];
        $unresolved = [];
        $unmapped = [];
        $photosLeftOut = [];
        $spotCheck = [];
        $withoutSeated = [];
        $withoutStanding = [];
        $exported = [];
        $seen = [];

        foreach ($rows as $number => $row) {
            $id8 = 1 === preg_match('/^[0-9a-f]{8}/', $row['ID'] ?? '', $match) ? $match[0] : null;
            if (null !== $id8) {
                $seen[$id8] = true;
            }

            try {
                $key = $this->folder($id8, $folders, $exported);
                try {
                    $paragraphs = WixRichText::paragraphs($row['Vorstellungstext'] ?? '');
                    $photoNotes = [];
                    $photos = OwnerPhotoList::fromRow($row, $photoNotes);
                } catch (\JsonException) {
                    throw InvalidWixExport::unresolvedRow('the Vorstellungstext is not valid rich text');
                }
                $facts = $this->mapper->map($row, $paragraphs);
                $kept = $this->photos($key, $photos, $cacheDir, $dryRun, $counts, $notes, $photosLeftOut);
                $outcome = $this->writer->write($root.'/venues/'.$key, $facts, $kept, $cacheDir, $dryRun);
            } catch (InvalidWixExport $exception) {
                $unresolved[] = \sprintf('row %d: %s', $number, $exception->getMessage());
                continue;
            }

            \assert(null !== $id8);
            $exported[$id8] = true;
            ++$counts[$outcome];
            foreach ([...$facts->notes, ...$photoNotes] as $note) {
                $notes[] = $key.': '.$note;
            }
            foreach ($facts->unmapped as $section => $answers) {
                foreach ($answers as $answer) {
                    $unmapped[$section][$answer] = ($unmapped[$section][$answer] ?? 0) + 1;
                }
            }
            if ($facts->spotCheck) {
                $spotCheck[] = $key;
            }
            if (null === $facts->seated) {
                $withoutSeated[] = $key;
            }
            if (null === $facts->standing) {
                $withoutStanding[] = $key;
            }
            self::tally($facts, $counts);
        }

        foreach ($existing as $key) {
            $id8 = LegacyVenueText::folderId($key);
            if (null !== $id8 && isset($exported[$id8])) {
                continue;
            }
            if (null === $id8 || !isset($seen[$id8])) {
                $notes[] = $key.': stale, not in the CSV; left alone';
            }
        }

        // Most frequent first, then alphabetical, so the report is the same on every run.
        ksort($unmapped);
        foreach (array_keys($unmapped) as $section) {
            $answers = $unmapped[$section];
            uksort($answers, static fn (string|int $a, string|int $b): int => [$answers[$b], (string) $a] <=> [$answers[$a], (string) $b]);
            $unmapped[$section] = $answers;
        }

        $report = new OwnerExportReport($dryRun, $counts, $notes, $unresolved, $unmapped, $photosLeftOut, $spotCheck, $withoutSeated, $withoutStanding);
        // Written in a dry run too: the unmapped answers are cells and never reach the console (PD8).
        ContentYamlFile::replace($root.'/'.self::REPORT_FILE, $report->markdown());

        return $report;
    }

    /**
     * The #21 folder of a row, found by the `-<id8>` ending of its key.
     *
     * @param array<string, list<string>> $folders
     * @param array<string, true>         $exported
     *
     * @throws InvalidWixExport
     */
    private function folder(?string $id8, array $folders, array $exported): string
    {
        if (null === $id8) {
            throw InvalidWixExport::unresolvedRow('the ID does not start with 8 hexadecimal characters');
        }
        if (isset($exported[$id8])) {
            throw InvalidWixExport::unresolvedRow('another row has the same first 8 characters of the ID');
        }
        $found = $folders[$id8] ?? [];
        if (\count($found) > 1) {
            throw InvalidWixExport::unresolvedRow('two folders end in the same ID');
        }
        if ([] === $found) {
            throw InvalidWixExport::noFolder();
        }

        return $found[0];
    }

    /**
     * The photos that go into the folder: checked in PD1's order, one download at a time.
     *
     * @param list<OwnerPhoto>   $photos
     * @param array<string, int> $counts
     * @param list<string>       $notes
     * @param list<string>       $photosLeftOut
     *
     * @return list<OwnerPhoto>
     */
    private function photos(string $key, array $photos, string $cacheDir, bool $dryRun, array &$counts, array &$notes, array &$photosLeftOut): array
    {
        $kept = [];
        $hashes = [];
        foreach ($photos as $index => $photo) {
            ++$counts['photos'];
            $reason = $this->reason($photo->id, $cacheDir, $dryRun, $counts, $hashes);
            if (null === $reason) {
                $kept[] = $photo;
                continue;
            }
            ++$counts['leftOut'];
            $notes[] = \sprintf('%s: photo %d left out: %s', $key, $index + 1, $reason);
            $photosLeftOut[] = \sprintf('%s: photo %d (%s): %s', $key, $index + 1, $photo->id, $reason);
        }

        return $kept;
    }

    /**
     * @param array<string, int>  $counts
     * @param array<string, true> $hashes
     */
    private function reason(string $id, string $cacheDir, bool $dryRun, array &$counts, array &$hashes): ?string
    {
        if (!WixPhotoCache::isId($id)) {
            return self::LEFT_OUT_BAD_ID;
        }
        // Known from the id, so no request is made for it (D10).
        if (str_ends_with(strtolower($id), '.avif')) {
            return self::LEFT_OUT_AVIF;
        }
        if ($this->cache->has($cacheDir, $id)) {
            ++$counts['cached'];
        } elseif ($dryRun) {
            ++$counts['wouldDownload'];

            return null;
        } elseif ($this->cache->fetch($cacheDir, $id)) {
            ++$counts['downloaded'];
        } else {
            ++$counts['failed'];

            return self::LEFT_OUT_DOWNLOAD;
        }

        return OwnerPhotoCheck::verdict($this->cache->path($cacheDir, $id), $hashes);
    }

    /**
     * @param array<string, int> $counts
     */
    private static function tally(OwnerFacts $facts, array &$counts): void
    {
        $counts['priceLevel'] += (int) (null !== $facts->priceLevel);
        $counts['seated'] += (int) (null !== $facts->seated);
        $counts['standing'] += (int) (null !== $facts->standing);
        $counts['party'] += (int) (null !== $facts->partyAllowed);
        $counts['noCurfew'] += (int) (false === $facts->hasCurfew);
        $counts['curfewAt'] += (int) (null !== $facts->curfewAt);
        $counts['ownCatering'] += (int) (null !== $facts->ownCatering);
        $counts['capacityNote'] += (int) (null !== $facts->capacityNote);
        $counts['partyNote'] += (int) (null !== $facts->partyNote);
        $counts['openingHours'] += (int) (null !== $facts->openingHours);
        $counts['parkingText'] += (int) (null !== $facts->parkingText);
    }
}
