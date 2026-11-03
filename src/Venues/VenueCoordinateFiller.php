<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Exception\InvalidWixExport;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * One-off: gives every venue folder without `geo:` the position Google finds for its name and
 * region, and lists what it did in <root>/owner/geocode-report.csv. A folder that already has
 * coordinates is never asked again, so a rerun only picks up what failed or was missing.
 * The report holds venue names and addresses, so it stays git-ignored with the rest of content/.
 */
final class VenueCoordinateFiller
{
    public const string REPORT_FILE = 'owner/geocode-report.csv';

    private const float PAUSE_SECONDS = 0.1;

    /** Canton code to the name the search should carry. */
    private const array CANTON_NAMES = [
        'zh' => 'Zürich', 'be' => 'Bern', 'lu' => 'Luzern', 'ur' => 'Uri', 'sz' => 'Schwyz',
        'ow' => 'Obwalden', 'nw' => 'Nidwalden', 'gl' => 'Glarus', 'zg' => 'Zug', 'fr' => 'Freiburg',
        'so' => 'Solothurn', 'bs' => 'Basel-Stadt', 'bl' => 'Basel-Landschaft', 'sh' => 'Schaffhausen',
        'ar' => 'Appenzell Ausserrhoden', 'ai' => 'Appenzell Innerrhoden', 'sg' => 'St. Gallen',
        'gr' => 'Graubünden', 'ag' => 'Aargau', 'tg' => 'Thurgau', 'ti' => 'Tessin', 'vd' => 'Waadt',
        'vs' => 'Wallis', 'ne' => 'Neuenburg', 'ge' => 'Genf', 'ju' => 'Jura',
    ];

    public function __construct(
        private readonly VenueGeocoder $geocoder,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{found: int, missed: int, failed: int, skipped: int}
     *
     * @throws InvalidWixExport for a venue.yaml that cannot be read
     */
    public function fill(string $root, bool $dryRun, ?int $limit = null): array
    {
        $counts = ['found' => 0, 'missed' => 0, 'failed' => 0, 'skipped' => 0];
        $rows = [['folder', 'name', 'query', 'status', 'matched_name', 'name_matches', 'address', 'lat', 'lng']];
        $asked = 0;

        $files = glob(rtrim($root, '/').'/venues/*/venue.yaml') ?: [];
        sort($files);
        foreach ($files as $file) {
            $folder = basename(\dirname($file));
            $document = ContentYamlFile::read($file, $folder);
            if (null === $document || !\is_string($document->name ?? null)) {
                continue;
            }
            if (isset($document->geo)) {
                ++$counts['skipped'];

                continue;
            }
            if (null !== $limit && $asked >= $limit) {
                break;
            }
            ++$asked;

            $query = $this->query($document);
            try {
                $hit = $this->geocoder->locate($query);
            } catch (ExceptionInterface) {
                ++$counts['failed'];
                $rows[] = [$folder, $document->name, $query, 'failed', '', '', '', '', ''];
                $this->clock->sleep(self::PAUSE_SECONDS);

                continue;
            }
            $this->clock->sleep(self::PAUSE_SECONDS);

            if (null === $hit) {
                ++$counts['missed'];
                $rows[] = [$folder, $document->name, $query, 'not found', '', '', '', '', ''];

                continue;
            }

            ++$counts['found'];
            $rows[] = [
                $folder, $document->name, $query, $dryRun ? 'would set' : 'set', $hit->name,
                self::namesMatch($document->name, $hit->name) ? 'yes' : 'NO', $hit->address, (string) $hit->lat, (string) $hit->lng,
            ];
            if (!$dryRun) {
                ContentYamlFile::set($document, 'geo', 'lat', $hit->lat);
                ContentYamlFile::set($document, 'geo', 'lng', $hit->lng);
                ContentYamlFile::replace($file, ContentYamlFile::dump($document));
            }
        }

        ContentYamlFile::replace(rtrim($root, '/').'/'.self::REPORT_FILE, self::csv($rows));

        return $counts;
    }

    private function query(\stdClass $document): string
    {
        $canton = isset($document->address->canton) && \is_string($document->address->canton)
            ? (self::CANTON_NAMES[$document->address->canton] ?? '')
            : '';
        $region = isset($document->translations->de->region) && \is_string($document->translations->de->region)
            ? $document->translations->de->region
            : '';

        return trim($document->name.' '.('' !== $canton ? $canton : $region).' Schweiz');
    }

    /** True when the two names share a word of four letters or more, case and umlauts folded. */
    public static function namesMatch(string $venue, string $found): bool
    {
        $words = static fn (string $text): array => array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue']))) ?: [],
            static fn (string $word): bool => mb_strlen($word) >= 4,
        );

        return [] !== array_intersect($words($venue), $words($found));
    }

    /**
     * @param list<list<string>> $rows
     */
    private static function csv(array $rows): string
    {
        $out = fopen('php://memory', 'w+');
        if (false === $out) {
            throw new \RuntimeException('Cannot build the report.');
        }
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
