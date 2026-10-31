<?php

declare(strict_types=1);

namespace App\Cms\ValueObject;

/**
 * The portrait limit of a CMS photo, which keeps gd inside php-fpm's memory_limit (#25). The rule alone, so
 * its boundaries are tested without a file: a photo at a limit is 20 MP that gd would really allocate.
 *
 * A shape is refused when its height exceeds the ratio times its width, compared without a
 * division, because `Assert\Image`'s minRatio rounds to two decimals and would let 3464×6929 through.
 * Landscapes have no limit.
 *
 * The rule reads the stored shape and, when EXIF turns the photo, the oriented one, because the generator
 * spends memory on both: the full bitmap as stored (rows rounded to 4 KiB pages), then the largest variant
 * as oriented. The oriented shape alone would pass a stored 1025×39024 that EXIF turns wide; the stored
 * shape alone would pass a stored wide file that EXIF turns very tall. A camera stores its pixels wide, so
 * a tall file that EXIF turns is not a real photo, and refusing it costs nothing.
 */
final readonly class PhotoUploadLimits
{
    public function __construct(
        public float $maxPortraitRatio,
    ) {
    }

    /**
     * @param int  $width     as stored
     * @param int  $height    as stored
     * @param bool $swapsAxes whether EXIF turns the photo by a quarter
     */
    public function isTooTall(int $width, int $height, bool $swapsAxes): bool
    {
        return $height > $this->maxPortraitRatio * $width
            || ($swapsAxes && $width > $this->maxPortraitRatio * $height);
    }
}
