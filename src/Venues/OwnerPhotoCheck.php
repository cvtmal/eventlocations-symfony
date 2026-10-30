<?php

declare(strict_types=1);

namespace App\Venues;

use App\Media\ImageVariantGenerator;

/**
 * Whether a downloaded photo would pass app:venues:import (#32 PD1, research D11). A file the
 * import refuses fails its whole venue, facts included, so the export leaves such a photo
 * out and reports it instead.
 *
 * The checks run in a fixed order: the type from the header (an AVIF the CSV calls .jpg),
 * the pixel ceiling before anything is decoded (a decode above it can end the run, PD2),
 * then gd's own decoder, the call the import makes, so the two cannot disagree (an animated
 * WebP, a PNG whose data is broken), and last the bytes against the venue's earlier photos,
 * because the import refuses the same image twice in one list.
 */
final class OwnerPhotoCheck
{
    public const string NOT_AN_IMAGE = 'not a JPEG, PNG or WebP image';
    public const string UNDECODABLE = 'gd cannot decode it';
    public const string DUPLICATE = 'the same image as an earlier photo';

    /**
     * Null when the photo can be imported, else the reason. A photo that passes adds its hash
     * to $seenHashes.
     *
     * @param array<string, true> $seenHashes SHA-256 of the venue's photos so far
     */
    public static function verdict(string $path, array &$seenHashes): ?string
    {
        $size = @getimagesize($path);
        if (false === $size || !\in_array($size[2], ContentReader::SNIFFED_IMAGE_TYPES, true)) {
            return self::NOT_AN_IMAGE;
        }
        if ($size[0] * $size[1] > ImageVariantGenerator::BATCH_MAX_PIXELS) {
            return self::tooLarge();
        }

        $bytes = (string) file_get_contents($path);
        $image = @imagecreatefromstring($bytes);
        if (false === $image) {
            return self::UNDECODABLE;
        }
        unset($image);

        $hash = hash('sha256', $bytes);
        if (isset($seenHashes[$hash])) {
            return self::DUPLICATE;
        }
        $seenHashes[$hash] = true;

        return null;
    }

    public static function tooLarge(): string
    {
        return \sprintf('above %d megapixels', intdiv(ImageVariantGenerator::BATCH_MAX_PIXELS, 1_000_000));
    }
}
