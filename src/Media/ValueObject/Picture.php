<?php

declare(strict_types=1);

namespace App\Media\ValueObject;

use App\Media\MediaPaths;

/**
 * One venue photo as `venue_picture()` renders it. Read models build it from a
 * `venue_photos` row: `ImageVariants::fromJson($row['variants'])`, the venue number and the
 * alt (the translation row, or `picture.alt_fallback` of the `media` catalogue).
 */
final readonly class Picture
{
    /**
     * @param int $width  the oriented original's width (aspect ratio for `<img width height>`)
     * @param int $height the oriented original's height
     *
     * @throws \InvalidArgumentException on a non-positive size or an invalid colour
     */
    public function __construct(
        public int $venueNumber,
        public string $photoId,
        public int $width,
        public int $height,
        public string $dominantColor,
        public ImageVariants $variants,
        public string $alt,
    ) {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException(\sprintf('A picture is at least 1×1 pixels, got %d×%d.', $width, $height));
        }
        GeneratedImage::assertColor($dominantColor);
    }

    /**
     * `/media/<venue-number>/<photo-id>-<width>.<ext>`.
     */
    public function url(int $width, ImageFormat $format): string
    {
        return \sprintf('%s/%d/%s-%d.%s', MediaPaths::URL_PREFIX, $this->venueNumber, $this->photoId, $width, $format->value);
    }
}
