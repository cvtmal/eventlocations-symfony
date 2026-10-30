<?php

declare(strict_types=1);

namespace App\Media;

use App\Media\Exception\InvalidMediaPath;
use App\Media\Exception\MissingOriginal;
use App\Media\ValueObject\GeneratedImage;

/**
 * Puts generated variants and PDFs where nginx serves them (`public/media/<venue-number>/`).
 *
 * The two-step photo write (#5 P1): generate into any directory first, create the
 * VenuePhoto with the result (its id exists only then), then copyVariants() under the id.
 */
final class MediaPublisher
{
    public function __construct(
        private readonly MediaPaths $paths,
    ) {
    }

    /**
     * Copies `<fromDirectory>/<fromBasename>-<w>.<ext>` to `<public>/<venueNumber>/<photoId>-<w>.<ext>` for every variant.
     *
     * The photo id is new, so nobody can request these URLs yet: the files are copied
     * directly, without the `.tmp` + rename of publishDocument() (on the macOS bind mount
     * each extra file operation costs about 0.3–0.5 ms, and DemoStory copies ~5,000 files).
     *
     * @throws \RuntimeException when a file cannot be copied
     */
    public function copyVariants(GeneratedImage $image, string $fromDirectory, string $fromBasename, int $venueNumber, string $photoId): void
    {
        $directory = $this->paths->photoDirectory($venueNumber);
        self::ensureDirectory($directory);
        foreach ($image->variants->widths as $width) {
            foreach ($image->variants->formats as $format) {
                $source = \sprintf('%s/%s-%d.%s', $fromDirectory, $fromBasename, $width, $format->value);
                $target = $this->paths->variantFile($venueNumber, $photoId, $width, $format);
                if (!@copy($source, $target)) {
                    throw new \RuntimeException(\sprintf('Cannot copy "%s" to "%s".', $source, $target));
                }
            }
        }
    }

    /**
     * Copies `MEDIA_ROOT/<relativePath>` to `<public>/<venueNumber>/<documentId>.pdf`.
     *
     * @return int the size in bytes
     *
     * @throws MissingOriginal   when the PDF is not under MEDIA_ROOT
     * @throws InvalidMediaPath  when the path leaves MEDIA_ROOT
     * @throws \RuntimeException when the file cannot be copied
     */
    public function publishDocument(string $relativePath, int $venueNumber, string $documentId): int
    {
        $source = $this->paths->original($relativePath);
        if (!is_file($source) || !is_readable($source)) {
            throw MissingOriginal::forPath($source);
        }
        $target = $this->paths->documentFile($venueNumber, $documentId);
        self::ensureDirectory($this->paths->photoDirectory($venueNumber));
        // Through .tmp + rename: the URL may be live (app:media:regenerate).
        $tmp = $target.'.tmp';
        if (!@copy($source, $tmp) || !rename($tmp, $target)) {
            @unlink($tmp);

            throw new \RuntimeException(\sprintf('Cannot copy "%s" to "%s".', $source, $target));
        }

        return (int) filesize($target);
    }

    /**
     * Deletes `<public>/<venueNumber>/` with everything in it; nothing happens when it is missing.
     */
    public function removeVenue(int $venueNumber): void
    {
        self::removeDirectory($this->paths->photoDirectory($venueNumber));
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create the directory "%s".', $directory));
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $directory.'/'.$entry;
            is_dir($path) && !is_link($path) ? self::removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
