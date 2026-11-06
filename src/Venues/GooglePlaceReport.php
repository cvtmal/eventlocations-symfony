<?php

declare(strict_types=1);

namespace App\Venues;

/**
 * Reads the place ID report a person checked (`GooglePlaceFinder::REPORT_FILE`): one row per venue, with `apply`
 * true only where it says `yes` (trimmed, any case). Columns are found by name, so #57's report and #53's older
 * one both read; the geocode report has neither `place_id` nor `apply` and is refused. `confidence` is #57's
 * column, null in a #53 report. The place ID is returned as written; the apply command checks it with
 * `GooglePlaceId::fromInput()`.
 */
final class GooglePlaceReport
{
    private const array REQUIRED = ['number', 'place_id', 'apply'];

    /**
     * @return list<array{number: int, placeId: string, apply: bool, confidence: ?string}>
     *
     * @throws \RuntimeException when the file is missing or its header lacks a required column
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
            $columns = [];
            foreach (\is_array($header) ? $header : [] as $index => $name) {
                if (\is_string($name) && !isset($columns[$name])) {
                    $columns[$name] = $index;
                }
            }
            if ([] !== array_diff(self::REQUIRED, array_keys($columns))) {
                throw new \RuntimeException(\sprintf('"%s" is not a place ID report: its first line must name the columns number, place_id and apply.', $file));
            }
            $confidence = $columns['confidence'] ?? null;

            $rows = [];
            while (false !== ($line = fgetcsv($handle, null, ',', '"', ''))) {
                if ([null] === $line) {
                    continue;
                }
                $rows[] = [
                    'number' => (int) ($line[$columns['number']] ?? 0),
                    'placeId' => trim((string) ($line[$columns['place_id']] ?? '')),
                    'apply' => 'yes' === strtolower(trim((string) ($line[$columns['apply']] ?? ''))),
                    'confidence' => null === $confidence ? null : trim((string) ($line[$confidence] ?? '')),
                ];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
