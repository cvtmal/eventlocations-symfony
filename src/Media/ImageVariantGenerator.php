<?php

declare(strict_types=1);

namespace App\Media;

use App\Media\Exception\MissingOriginal;
use App\Media\Exception\UnreadableImage;
use App\Media\ValueObject\GeneratedImage;
use App\Media\ValueObject\ImageFormat;
use App\Media\ValueObject\ImageVariants;

/**
 * Renders the S9 variants of one original with gd, at import time (never on request):
 * every width of WIDTHS up to the original's width, in every available format, as
 * `<targetDirectory>/<basename>-<width>.<avif|webp|jpg>`.
 *
 * - EXIF orientation is applied (JPEG only), after the first downscale so only one full-size
 *   bitmap is held (songrequest's PhotoResizer pattern).
 * - Re-encoding strips every metadata block (EXIF, GPS, ICC; ICC is not converted, so
 *   non-sRGB originals shift slightly). Alpha is flattened onto white.
 * - Each smaller width is resampled from the next larger one (cascade).
 * - Formats are detected at runtime: AVIF and WebP only if this gd supports them, JPEG
 *   always. The list is stored per photo, so a host without AVIF degrades to WebP + JPEG.
 * - Every file is written as `.tmp`, checked (imageavif() returns true even when libgd
 *   fails) and renamed; stale widths of the same basename are removed.
 *
 * Cost: about 2.1–2.3 CPU-s per 15 MP original, about 1.56 MB of variants.
 */
final class ImageVariantGenerator
{
    /** S9's widths; a variant is never wider than the oriented original. */
    public const array WIDTHS = [360, 640, 960, 1280, 1920];

    /** gd's defaults (-1); 25–28 KB at 640 px on real photos. Speed 8 is the lever for a faster bulk run. */
    public const int AVIF_QUALITY = 52;
    public const int AVIF_SPEED = 6;
    public const int WEBP_QUALITY = 80;
    /** Progressive. */
    public const int JPEG_QUALITY = 82;

    /** Bump when the rendering changes, so caches keyed by fingerprint() are rebuilt. */
    public const int SETTINGS_VERSION = 1;

    /**
     * The memory a console batch (import, regenerate) needs: gd holds a decoded
     * original at about 5 bytes a pixel, so a 60 MP photo stops PHP's stock 256M (#32 D9).
     */
    public const string BATCH_MEMORY_LIMIT = '512M';

    /** What a batch command prints when raiseBatchMemoryLimit() fails; it goes on regardless. */
    public const string BATCH_MEMORY_WARNING = 'memory_limit could not be raised to '.self::BATCH_MEMORY_LIMIT.': photos above about 40 MP may stop the run.';

    /** @var list<ImageFormat> */
    private readonly array $formats;

    /**
     * @param list<ImageFormat>|null $formats null: what this gd supports (availableFormats()); tests pass a subset
     */
    public function __construct(?array $formats = null)
    {
        $this->formats = (new ImageVariants([1], $formats ?? self::availableFormats()))->formats;
    }

    /**
     * Raises memory_limit to BATCH_MEMORY_LIMIT for a console batch, never lowers it (a host's
     * 1G or -1 stays), and tells whether the limit now suffices; false where ini_set() is
     * disabled, so the caller can warn.
     *
     * Web requests keep php-fpm's limit, behind the limits of the upload route (#25): 20 MP
     * (MEDIA_MAX_PIXELS) and at most 2:1 portrait for every type (MEDIA_MAX_PORTRAIT_RATIO,
     * Cms\ValueObject\PhotoUploadLimits). None of these limits belongs
     * in this class or in PhotoPipeline: a pixel check here would fail the imported photos above
     * 40 MP (#32 PD4). Never call this from a request.
     */
    public static function raiseBatchMemoryLimit(): bool
    {
        $wanted = ini_parse_quantity(self::BATCH_MEMORY_LIMIT);
        if (self::memoryLimitReaches($wanted)) {
            return true;
        }
        @ini_set('memory_limit', self::BATCH_MEMORY_LIMIT);

        return self::memoryLimitReaches($wanted);
    }

    /**
     * Whether generate() turns this file by a quarter, so that its width and height trade places. A
     * reading of the orientation, not a limit: the upload route uses it to see the shape as oriented.
     */
    public static function swapsAxes(string $file): bool
    {
        $head = is_file($file) ? @file_get_contents($file, false, null, 0, 3) : false;

        return \is_string($head) && self::isJpeg($head) && self::turns(self::orientation($file));
    }

    /**
     * @return list<ImageFormat> in preference order, always with Jpeg
     */
    public static function availableFormats(): array
    {
        $formats = [];
        if (\function_exists('imageavif') && 0 !== (imagetypes() & \IMG_AVIF)) {
            $formats[] = ImageFormat::Avif;
        }
        if (0 !== (imagetypes() & \IMG_WEBP)) {
            $formats[] = ImageFormat::Webp;
        }
        $formats[] = ImageFormat::Jpeg;

        return $formats;
    }

    /**
     * @return list<ImageFormat>
     */
    public function formats(): array
    {
        return $this->formats;
    }

    /**
     * Identifies the output for a given input: the settings version, the encoder settings and the formats.
     */
    public function fingerprint(): string
    {
        return \sprintf(
            'v%d;w=%s;avif=%d/%d;webp=%d;jpg=%d;f=%s',
            self::SETTINGS_VERSION,
            implode(',', self::WIDTHS),
            self::AVIF_QUALITY,
            self::AVIF_SPEED,
            self::WEBP_QUALITY,
            self::JPEG_QUALITY,
            implode(',', array_map(static fn (ImageFormat $format): string => $format->value, $this->formats)),
        );
    }

    /**
     * @throws MissingOriginal   when the source file cannot be read
     * @throws UnreadableImage   when gd cannot decode it
     * @throws \RuntimeException when an encoder or the file system fails
     */
    public function generate(string $sourceFile, string $targetDirectory, string $basename): GeneratedImage
    {
        $bytes = is_file($sourceFile) ? @file_get_contents($sourceFile) : false;
        if (false === $bytes) {
            throw MissingOriginal::forPath($sourceFile);
        }
        $isJpeg = self::isJpeg($bytes);
        // gd warns on undecodable input; the exception is the answer.
        $source = '' === $bytes ? false : @imagecreatefromstring($bytes);
        unset($bytes);
        if (false === $source) {
            throw UnreadableImage::forFile($sourceFile);
        }
        if (!imageistruecolor($source)) {
            imagepalettetotruecolor($source);
        }

        $orientation = $isJpeg ? self::orientation($sourceFile) : 1;
        $rawWidth = imagesx($source);
        $rawHeight = imagesy($source);
        $turned = self::turns($orientation);
        $width = $turned ? $rawHeight : $rawWidth;
        $height = $turned ? $rawWidth : $rawHeight;

        $widths = array_values(array_filter(self::WIDTHS, static fn (int $w): bool => $w <= $width));
        if ([] === $widths) {
            $widths = [$width];
        }

        self::ensureDirectory($targetDirectory);

        // Shrink first, turn upright after: rotating the full-size bitmap would hold a second copy of it.
        $largest = $widths[\count($widths) - 1];
        $largestHeight = self::heightFor($largest, $width, $height);
        $canvas = $turned
            ? self::whiteCanvas($largestHeight, $largest)
            : self::whiteCanvas($largest, $largestHeight);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $rawWidth, $rawHeight);
        unset($source);
        $variant = self::applyOrientation($canvas, $orientation);
        unset($canvas);

        $written = [];
        for ($index = \count($widths) - 1; $index >= 0; --$index) {
            $w = $widths[$index];
            if ($w !== imagesx($variant)) {
                $smaller = self::whiteCanvas($w, self::heightFor($w, $width, $height));
                imagecopyresampled($smaller, $variant, 0, 0, 0, 0, imagesx($smaller), imagesy($smaller), imagesx($variant), imagesy($variant));
                $variant = $smaller;
            }
            foreach ($this->formats as $format) {
                $file = \sprintf('%s/%s-%d.%s', $targetDirectory, $basename, $w, $format->value);
                $this->encode($variant, $format, $file);
                $written[] = basename($file);
            }
        }

        $dominantColor = self::averageColor($variant);
        unset($variant);

        self::removeStale($targetDirectory, $basename, $written);

        return new GeneratedImage($width, $height, $dominantColor, new ImageVariants($widths, $this->formats));
    }

    private function encode(\GdImage $image, ImageFormat $format, string $file): void
    {
        $tmp = $file.'.tmp';
        $ok = match ($format) {
            ImageFormat::Avif => imageavif($image, $tmp, self::AVIF_QUALITY, self::AVIF_SPEED),
            ImageFormat::Webp => imagewebp($image, $tmp, self::WEBP_QUALITY),
            ImageFormat::Jpeg => imageinterlace($image, true) && imagejpeg($image, $tmp, self::JPEG_QUALITY),
        };

        $head = $ok && is_file($tmp) ? (string) file_get_contents($tmp, false, null, 0, 32) : '';
        if (!self::hasMagic($format, $head)) {
            @unlink($tmp);

            throw new \RuntimeException(\sprintf('gd failed to encode "%s" as %s.', $file, $format->value));
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);

            throw new \RuntimeException(\sprintf('Cannot move "%s" into place.', $tmp));
        }
    }

    private static function hasMagic(ImageFormat $format, string $head): bool
    {
        return match ($format) {
            ImageFormat::Jpeg => self::isJpeg($head),
            ImageFormat::Webp => str_starts_with($head, 'RIFF') && 'WEBP' === substr($head, 8, 4),
            ImageFormat::Avif => 'ftyp' === substr($head, 4, 4) && (str_contains($head, 'avif') || str_contains($head, 'avis')),
        };
    }

    private static function isJpeg(string $head): bool
    {
        return str_starts_with($head, "\xFF\xD8\xFF");
    }

    /**
     * EXIF orientations 5–8 turn by a quarter; 1–4 keep the axes.
     */
    private static function turns(int $orientation): bool
    {
        return $orientation >= 5;
    }

    /**
     * EXIF orientation 1–8; 1 when the block is missing or broken (exif warns on a broken one).
     */
    private static function orientation(string $file): int
    {
        $exif = @exif_read_data($file);
        $orientation = \is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return \is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /**
     * Turns the stored pixels upright. gd rotates counter-clockwise for a positive angle.
     */
    private static function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if (false === $rotated) {
            throw new \RuntimeException(\sprintf('gd failed to apply EXIF orientation %d.', $orientation));
        }

        match ($orientation) {
            2, 5, 7 => imageflip($rotated, \IMG_FLIP_HORIZONTAL),
            4 => imageflip($rotated, \IMG_FLIP_VERTICAL),
            default => null,
        };

        return $rotated;
    }

    /**
     * @return int<1, max>
     */
    private static function heightFor(int $width, int $originalWidth, int $originalHeight): int
    {
        return max(1, (int) round($width * $originalHeight / $originalWidth));
    }

    /**
     * Truecolor and white, so transparent pixels end up white in every format (JPEG has no alpha).
     */
    private static function whiteCanvas(int $width, int $height): \GdImage
    {
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));
        // Not imagefill(): its flood-fill stack costs about as much as the canvas itself.
        imagefilledrectangle($canvas, 0, 0, imagesx($canvas) - 1, imagesy($canvas) - 1, (int) imagecolorallocate($canvas, 255, 255, 255));

        return $canvas;
    }

    /**
     * The average colour, `#rrggbb` in lower case: the placeholder shown until the image loads.
     */
    private static function averageColor(\GdImage $image): string
    {
        $pixel = imagecreatetruecolor(1, 1);
        imagecopyresampled($pixel, $image, 0, 0, 0, 0, 1, 1, imagesx($image), imagesy($image));
        $rgb = (int) imagecolorat($pixel, 0, 0);

        return \sprintf('#%02x%02x%02x', ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create the directory "%s".', $directory));
        }
    }

    /**
     * Deletes `<basename>-<width>.<ext>` files of earlier runs that are not in the new set.
     * One glob per extension: musl (Alpine) has no GLOB_BRACE.
     *
     * @param list<string> $keep file names just written
     */
    private static function removeStale(string $directory, string $basename, array $keep): void
    {
        $pattern = '/^'.preg_quote($basename, '/').'-\d+\.(avif|webp|jpg)$/';
        foreach (ImageFormat::cases() as $format) {
            foreach (glob(\sprintf('%s/%s-*.%s', $directory, $basename, $format->value)) ?: [] as $file) {
                $name = basename($file);
                if (1 === preg_match($pattern, $name) && !\in_array($name, $keep, true)) {
                    @unlink($file);
                }
            }
        }
    }

    private static function memoryLimitReaches(int $bytes): bool
    {
        $limit = (string) \ini_get('memory_limit');

        return '-1' === $limit || ini_parse_quantity($limit) >= $bytes;
    }
}
