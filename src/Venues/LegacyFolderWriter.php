<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Exception\InvalidWixExport;
use App\Venues\ValueObject\Content\LegacyVenue;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

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
    private const int PARSE_FLAGS = Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE;
    private const int DUMP_FLAGS = Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE;

    /**
     * @return self::CREATED|self::UPDATED|self::UNCHANGED
     *
     * @throws InvalidWixExport for a file that cannot be read; it stays as it is
     */
    public function write(string $folder, LegacyVenue $venue, bool $dryRun): string
    {
        $file = $folder.'/'.self::FILE;
        $before = is_file($file) ? (string) file_get_contents($file) : null;
        $document = null === $before ? new \stdClass() : self::parse($before, basename($folder));

        self::apply($document, $venue);
        $after = Yaml::dump($document, 8, 2, self::DUMP_FLAGS);

        if ($after === $before) {
            return self::UNCHANGED;
        }
        if (!$dryRun) {
            self::replace($folder, $file, $after);
        }

        return null === $before ? self::CREATED : self::UPDATED;
    }

    private static function parse(string $yaml, string $key): \stdClass
    {
        try {
            $document = Yaml::parse($yaml, self::PARSE_FLAGS);
        } catch (ParseException) {
            throw InvalidWixExport::unreadableFolder($key);
        }
        if (!$document instanceof \stdClass) {
            throw InvalidWixExport::unreadableFolder($key);
        }
        foreach (['capacity', 'address', 'contact', 'translations'] as $section) {
            if (isset($document->{$section}) && !$document->{$section} instanceof \stdClass) {
                throw InvalidWixExport::unreadableFolder($key);
            }
        }
        if (isset($document->translations->de) && !$document->translations->de instanceof \stdClass) {
            throw InvalidWixExport::unreadableFolder($key);
        }

        return $document;
    }

    private static function apply(\stdClass $document, LegacyVenue $venue): void
    {
        $document->name = $venue->name;
        $document->tier ??= self::DEFAULT_TIER;
        $document->capacity ??= new \stdClass();
        $document->capacity->max = $venue->capacityMax;

        self::set($document, 'address', 'canton', $venue->cantonCode);
        self::set($document, 'contact', 'phone', $venue->phone);
        self::set($document, 'contact', 'inquiry_email', $venue->inquiryEmail);
        self::set($document, 'contact', 'website', $venue->website);

        $document->translations ??= new \stdClass();
        $document->translations->de ??= new \stdClass();
        $document->translations->de->summary = $venue->summary;
        self::set($document->translations, 'de', 'description', $venue->description);
        self::set($document->translations, 'de', 'region', $venue->region);
    }

    /**
     * Sets a path, or removes it for null together with a section left empty.
     */
    private static function set(\stdClass $parent, string $section, string $field, ?string $value): void
    {
        if (null !== $value) {
            $parent->{$section} ??= new \stdClass();
            $parent->{$section}->{$field} = $value;

            return;
        }

        if (isset($parent->{$section})) {
            unset($parent->{$section}->{$field});
            if ([] === get_object_vars($parent->{$section})) {
                unset($parent->{$section});
            }
        }
    }

    private static function replace(string $folder, string $file, string $yaml): void
    {
        if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $folder));
        }
        $temporary = $folder.'/.'.self::FILE.'.'.bin2hex(random_bytes(4));
        if (false === file_put_contents($temporary, $yaml) || !rename($temporary, $file)) {
            @unlink($temporary);

            throw new \RuntimeException(\sprintf('Cannot write "%s".', $file));
        }
    }
}
