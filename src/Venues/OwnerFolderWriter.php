<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Exception\InvalidWixExport;
use App\Venues\ValueObject\Content\OwnerFacts;
use App\Venues\ValueObject\Content\OwnerPhoto;

/**
 * Overlays the paths #32 owns (research D1) on a folder #21's export wrote, and keeps every
 * other key: #21's paths, `tier`, `faq.daylight`, `terms.furniture`, rooms, documents. A value
 * the CSV no longer holds removes its path, and a section left empty goes with it. `summary`
 * stays #21's (D18), so the two exports never write the same path and either order leaves
 * the bytes alone.
 *
 * Files go in a fixed order, so a killed run leaves a folder the import can read and the next
 * run repairs: the photos are linked or copied in first, then venue.yaml is replaced, and only
 * then are the files no longer listed deleted (an unlisted file fails the folder). A hard
 * link is a normal file to the import, a symbolic link is refused (D12).
 */
final class OwnerFolderWriter
{
    public const string UPDATED = 'updated';
    public const string UNCHANGED = 'unchanged';

    private const string FILE = 'venue.yaml';
    private const string PHOTOS = 'photos';

    /** The sections #32 writes into, which must be maps when present. */
    private const array SECTIONS = ['terms', 'main_terms', 'faq', 'party', 'capacity', 'translations', 'translations.de'];

    /** The term groups #32 owns; `furniture` is left to the hand-made folders. */
    private const array TERM_GROUPS = ['setting', 'building', 'suitable_for', 'infrastructure', 'technology', 'surroundings', 'ceremony', 'amenity', 'dining', 'vendor', 'usage', 'language'];
    private const array MAIN_TERM_GROUPS = ['setting', 'building', 'suitable_for'];

    /**
     * @param list<OwnerPhoto> $photos the gallery in order, each a file `<cacheDir>/<id>`
     *                                 (in a dry run it may not be downloaded yet)
     *
     * @return self::UPDATED|self::UNCHANGED
     *
     * @throws InvalidWixExport without a venue.yaml, or for one that cannot be read; nothing is written
     */
    public function write(string $folder, OwnerFacts $facts, array $photos, string $cacheDir, bool $dryRun): string
    {
        $file = $folder.'/'.self::FILE;
        $document = ContentYamlFile::read($file, basename($folder), self::SECTIONS);
        if (null === $document) {
            throw InvalidWixExport::noFolder();
        }
        $before = (string) file_get_contents($file);

        self::apply($document, $facts, $photos);
        $after = ContentYamlFile::dump($document);

        $listed = array_map(static fn (OwnerPhoto $photo): string => $photo->id, $photos);
        $present = self::entries($folder.'/'.self::PHOTOS);
        $missing = array_values(array_diff($listed, $present));
        $unlisted = array_values(array_diff($present, $listed));

        if ($after === $before && [] === $missing && [] === $unlisted) {
            return self::UNCHANGED;
        }
        if ($dryRun) {
            return self::UPDATED;
        }

        foreach ($missing as $id) {
            self::place($cacheDir.'/'.$id, $folder.'/'.self::PHOTOS.'/'.$id);
        }
        if ($after !== $before) {
            ContentYamlFile::replace($file, $after);
        }
        foreach ($unlisted as $entry) {
            unlink($folder.'/'.self::PHOTOS.'/'.$entry);
        }

        return self::UPDATED;
    }

    /**
     * @param list<OwnerPhoto> $photos
     */
    private static function apply(\stdClass $document, OwnerFacts $facts, array $photos): void
    {
        self::setTop($document, 'price_level', $facts->priceLevel?->value);
        ContentYamlFile::set($document, 'capacity', 'seated', $facts->seated);
        ContentYamlFile::set($document, 'capacity', 'standing', $facts->standing);
        ContentYamlFile::set($document, 'faq', 'own_catering', $facts->ownCatering?->value);

        // party is a block of three, written only with a value: a null block is rejected.
        $party = new \stdClass();
        if (null !== $facts->partyAllowed) {
            $party->allowed = $facts->partyAllowed->value;
        }
        if (null !== $facts->hasCurfew) {
            $party->has_curfew = $facts->hasCurfew;
        }
        if (true === $facts->hasCurfew && null !== $facts->curfewAt) {
            $party->curfew_at = $facts->curfewAt;
        }
        self::setTop($document, 'party', [] === get_object_vars($party) ? null : $party);

        foreach (self::TERM_GROUPS as $group) {
            $codes = $facts->terms[$group] ?? [];
            ContentYamlFile::set($document, 'terms', $group, [] === $codes ? null : $codes);
        }
        foreach (self::MAIN_TERM_GROUPS as $group) {
            ContentYamlFile::set($document, 'main_terms', $group, $facts->mainTerms[$group] ?? null);
        }

        $document->translations ??= new \stdClass();
        ContentYamlFile::set($document->translations, 'de', 'ambience', $facts->ambience);
        ContentYamlFile::set($document->translations, 'de', 'capacity_note', $facts->capacityNote);
        ContentYamlFile::set($document->translations, 'de', 'party_note', $facts->partyNote);
        ContentYamlFile::set($document->translations, 'de', 'opening_hours', $facts->openingHours);
        ContentYamlFile::set($document->translations, 'de', 'parking_text', $facts->parkingText);

        $entries = [];
        foreach ($photos as $photo) {
            $entry = new \stdClass();
            $entry->file = $photo->id;
            if (null !== $photo->alt) {
                $entry->alt = new \stdClass();
                $entry->alt->de = $photo->alt;
            }
            $entries[] = $entry;
        }
        self::setTop($document, 'photos', [] === $entries ? null : $entries);
    }

    /**
     * Sets a top-level key in place (a key already there keeps its position), or removes it.
     */
    private static function setTop(\stdClass $document, string $key, mixed $value): void
    {
        if (null === $value) {
            unset($document->{$key});
        } else {
            $document->{$key} = $value;
        }
    }

    /**
     * A hard link to the cached file when the file system allows it, else a copy through a
     * temporary name, so a half-copied photo never carries the final name.
     */
    private static function place(string $source, string $target): void
    {
        $directory = \dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $directory));
        }
        if (@link($source, $target)) {
            return;
        }
        $temporary = $directory.'/.'.basename($target).'.'.bin2hex(random_bytes(4));
        if (!@copy($source, $temporary) || !rename($temporary, $target)) {
            @unlink($temporary);

            throw new \RuntimeException(\sprintf('Cannot copy a photo into "%s".', $directory));
        }
    }

    /**
     * The files in photos/, without dot files, which the import ignores too.
     *
     * @return list<string>
     */
    private static function entries(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        return array_values(array_filter(scandir($directory) ?: [], static fn (string $entry): bool => !str_starts_with($entry, '.') && is_file($directory.'/'.$entry)));
    }
}
