<?php

declare(strict_types=1);

namespace App\Tests\Support\Media;

/**
 * File-system clean-up for media tests.
 */
final class MediaFiles
{
    /**
     * Deletes the directory with everything in it; nothing happens when it is missing.
     */
    public static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $directory.'/'.$entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    /**
     * @return array{int, int} width and height of an image file (AVIF through gd when getimagesize() cannot read it)
     */
    public static function size(string $file): array
    {
        $size = @getimagesize($file);
        if (false !== $size && $size[0] > 0) {
            return [$size[0], $size[1]];
        }
        $image = imagecreatefromstring((string) file_get_contents($file));
        \assert(false !== $image);

        return [imagesx($image), imagesy($image)];
    }

    public static function image(string $file): \GdImage
    {
        $image = imagecreatefromstring((string) file_get_contents($file));
        \assert(false !== $image);

        return $image;
    }
}
