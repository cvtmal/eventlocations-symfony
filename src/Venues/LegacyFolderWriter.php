<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Exception\InvalidWixExport;
use App\Venues\ValueObject\Content\LegacyVenue;

/**
 * Writes the paths #21 owns (D7) into a venue.yaml and keeps every other key, because #32
 * overlays the same files and a re-run must not wipe that. `tier` is written only when the
 * file has none: #32 sets the paying tiers. photos/, rooms/ and documents/ are never touched.
 *
 * The output is deterministic, so a second run with the same data leaves the file's bytes
 * alone. Comments do not survive a change.
 */
final class LegacyFolderWriter
{
    public const string CREATED = 'created';
    public const string UPDATED = 'updated';
    public const string UNCHANGED = 'unchanged';

    private const string FILE = 'venue.yaml';
    private const string DEFAULT_TIER = 'basic';

    /** The sections #21 writes into, which must be maps when present. */
    private const array SECTIONS = ['capacity', 'address', 'contact', 'translations', 'translations.de'];

    /**
     * @return self::CREATED|self::UPDATED|self::UNCHANGED
     *
     * @throws InvalidWixExport for a file that cannot be read; it stays as it is
     */
    public function write(string $folder, LegacyVenue $venue, bool $dryRun): string
    {
        $file = $folder.'/'.self::FILE;
        $before = is_file($file) ? (string) file_get_contents($file) : null;
        $document = ContentYamlFile::read($file, basename($folder), self::SECTIONS) ?? new \stdClass();

        self::apply($document, $venue);
        $after = ContentYamlFile::dump($document);

        if ($after === $before) {
            return self::UNCHANGED;
        }
        if (!$dryRun) {
            ContentYamlFile::replace($file, $after);
        }

        return null === $before ? self::CREATED : self::UPDATED;
    }

    private static function apply(\stdClass $document, LegacyVenue $venue): void
    {
        $document->name = $venue->name;
        $document->tier ??= self::DEFAULT_TIER;
        $document->capacity ??= new \stdClass();
        $document->capacity->max = $venue->capacityMax;

        ContentYamlFile::set($document, 'address', 'canton', $venue->cantonCode);
        ContentYamlFile::set($document, 'contact', 'phone', $venue->phone);
        ContentYamlFile::set($document, 'contact', 'inquiry_email', $venue->inquiryEmail);
        ContentYamlFile::set($document, 'contact', 'website', $venue->website);

        $document->translations ??= new \stdClass();
        $document->translations->de ??= new \stdClass();
        $document->translations->de->summary = $venue->summary;
        ContentYamlFile::set($document->translations, 'de', 'description', $venue->description);
        ContentYamlFile::set($document->translations, 'de', 'region', $venue->region);
    }
}
