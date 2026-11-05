<?php

declare(strict_types=1);

namespace App\Venues;

/**
 * Reads the place ID report a person checked (#53, `GooglePlaceFinder::REPORT_FILE`): one row per venue, with
 * `apply` true only where the person wrote `yes` (trimmed, any case). The place ID is returned as written; the
 * apply command checks it with `GooglePlaceId::fromInput()`.
 */
final class GooglePlaceReport
{
    /**
     * @return list<array{number: int, placeId: string, apply: bool}>
     *
     * @throws \RuntimeException when the file is missing or its header is not the finder's
     */
    public static function read(string $file): array
    {
        $handle = is_file($file) ? fopen($file, 'r') : false;
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('No report at "%s". Run app:venues:find-google-places first.', $file));
        }

        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            // A spreadsheet may save the file with a byte order mark.
            if (\is_array($header) && \is_string($header[0] ?? null)) {
                $header[0] = preg_replace('/\A\xEF\xBB\xBF/', '', $header[0]);
            }
            if (GooglePlaceFinder::HEADER !== $header) {
                throw new \RuntimeException(\sprintf('"%s" is not a place ID report: its first line must be "%s".', $file, implode(',', GooglePlaceFinder::HEADER)));
            }
            $columns = array_flip(GooglePlaceFinder::HEADER);

            $rows = [];
            while (false !== ($line = fgetcsv($handle, null, ',', '"', ''))) {
                if ([null] === $line) {
                    continue;
                }
                $rows[] = [
                    'number' => (int) ($line[$columns['number']] ?? 0),
                    'placeId' => trim((string) ($line[$columns['place_id']] ?? '')),
                    'apply' => 'yes' === strtolower(trim((string) ($line[$columns['apply']] ?? ''))),
                ];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
