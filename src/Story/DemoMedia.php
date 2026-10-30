<?php

declare(strict_types=1);

namespace App\Story;

use App\Media\ImageVariantGenerator;
use App\Media\MediaPaths;
use App\Media\MediaPublisher;
use App\Media\ValueObject\GeneratedImage;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Demo photos and PDFs for DemoStory (#5). Nothing comes from eventlokale.
 *
 * - 30 originals drawn with gd (a two-colour diagonal gradient, a few shapes, a large initial
 *   made by scaling up imagestring(), since gd here has no FreeType), mixed aspect ratios,
 *   six of them 2000 px wide; written once to `MEDIA_ROOT/demo/photo-NN.jpg`.
 * - Their variants are rendered once per original into `var/demo-media/<key>/` (key: the
 *   generator's fingerprint and the original's hash, shared by dev and test) and copied per
 *   photo id. The first, cold run renders all 30 (about 10–15 s); later runs only copy.
 * - Documents are minimal one-page PDFs written to `MEDIA_ROOT/<path>`.
 */
final class DemoMedia
{
    public const int ORIGINALS = 30;

    /** [width ratio, height ratio], cycled by original number; 3:2 is the most common. */
    private const array RATIOS = [[3, 2], [4, 3], [3, 2], [16, 9], [3, 2], [2, 3], [3, 2], [1, 1]];

    /** @var array<int, array{GeneratedImage, string}> variants() per original, for this process */
    private array $loaded = [];

    public function __construct(
        private readonly ImageVariantGenerator $generator,
        private readonly MediaPaths $paths,
        private readonly MediaPublisher $publisher,
        #[Autowire('%kernel.project_dir%/var/demo-media')]
        private readonly string $cacheDirectory,
    ) {
    }

    /**
     * @param int<1, 30> $number
     */
    public static function originalPath(int $number): string
    {
        return \sprintf('demo/photo-%02d.jpg', $number);
    }

    /**
     * Draws every original and renders every cache entry that is missing.
     */
    public function warm(): void
    {
        for ($number = 1; $number <= self::ORIGINALS; ++$number) {
            $this->variants($number);
        }
    }

    /**
     * The variants of original $number (rendered on first use) and the directory holding them as `v-<w>.<ext>`.
     *
     * @param int<1, 30> $number
     *
     * @return array{GeneratedImage, string}
     */
    public function variants(int $number): array
    {
        return $this->loaded[$number] ??= $this->load($number);
    }

    /**
     * @param int<1, 30> $number
     *
     * @return array{GeneratedImage, string}
     */
    private function load(int $number): array
    {
        $original = $this->ensureOriginal($number);
        $directory = \sprintf('%s/%s', $this->cacheDirectory, sha1($this->generator->fingerprint().(string) sha1_file($original)));
        $manifest = $directory.'/image.json';

        if (is_file($manifest)) {
            $data = json_decode((string) file_get_contents($manifest), true);
            if (\is_array($data)) {
                return [GeneratedImage::fromArray($data), $directory];
            }
        }

        $image = $this->generator->generate($original, $directory, 'v');
        file_put_contents($manifest, json_encode($image->toArray(), \JSON_THROW_ON_ERROR));

        return [$image, $directory];
    }

    public function publishPhoto(GeneratedImage $image, string $cacheDirectory, int $venueNumber, string $photoId): void
    {
        $this->publisher->copyVariants($image, $cacheDirectory, 'v', $venueNumber, $photoId);
    }

    /**
     * Writes a one-page PDF with the title to `MEDIA_ROOT/<relativePath>`.
     *
     * @return int its size in bytes
     */
    public function writePdf(string $relativePath, string $title): int
    {
        $text = static fn (string $value): string => strtr((new AsciiSlugger('de'))->slug($value, ' ')->toString(), ['\\' => '\\\\', '(' => '\(', ')' => '\)']);
        $content = \sprintf("BT /F1 24 Tf 72 760 Td (%s) Tj 0 -36 Td /F1 12 Tf (Demo-Dokument, fiktiv) Tj ET\n", $text($title));
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            \sprintf("<< /Length %d >>\nstream\n%sendstream", \strlen($content), $content),
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = \strlen($pdf);
            $pdf .= \sprintf("%d 0 obj\n%s\nendobj\n", $index + 1, $object);
        }
        $xref = \strlen($pdf);
        $pdf .= \sprintf("xref\n0 %d\n0000000000 65535 f \n", \count($objects) + 1);
        foreach ($offsets as $offset) {
            $pdf .= \sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= \sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", \count($objects) + 1, $xref);

        $file = $this->paths->original($relativePath);
        self::ensureDirectory(\dirname($file));
        file_put_contents($file, $pdf);

        return \strlen($pdf);
    }

    public function publishDocument(string $relativePath, int $venueNumber, string $documentId): int
    {
        return $this->publisher->publishDocument($relativePath, $venueNumber, $documentId);
    }

    /**
     * Removes what an earlier database left in `public/media/<number>/` (numbers restart after make db-fresh).
     */
    public function resetVenue(int $venueNumber): void
    {
        $this->publisher->removeVenue($venueNumber);
    }

    /**
     * @param int<1, 30> $number
     */
    private function ensureOriginal(int $number): string
    {
        $file = $this->paths->original(self::originalPath($number));
        if (is_file($file)) {
            return $file;
        }

        [$ratioWidth, $ratioHeight] = self::RATIOS[$number % \count(self::RATIOS)];
        $width = 0 === $number % 5 ? 2000 : [1200, 1400, 1600][$number % 3];
        $height = max(1, (int) round($width * $ratioHeight / $ratioWidth));
        $random = new Randomizer(new Mt19937($number));

        $image = imagecreatetruecolor($width, $height);
        self::gradient($image, self::hsl(($number * 47) % 360, 0.28, 0.50), self::hsl(($number * 47 + 50) % 360, 0.35, 0.78));

        for ($shape = $random->getInt(2, 3); $shape > 0; --$shape) {
            [$red, $green, $blue] = 1 === $random->getInt(0, 1) ? [255, 255, 255] : [40, 32, 28];
            $color = (int) imagecolorallocatealpha($image, $red, $green, $blue, $random->getInt(70, 100));
            if (0 === $shape % 2) {
                $size = $random->getInt(intdiv($height, 4), intdiv($height * 3, 4));
                imagefilledellipse($image, $random->getInt(0, $width), $random->getInt(0, $height), $size, $size, $color);
            } else {
                $base = $random->getInt(intdiv($height, 2), $height);
                imagefilledpolygon($image, [
                    $random->getInt(-intdiv($width, 4), intdiv($width, 2)), $height,
                    $random->getInt(intdiv($width, 4), $width), $height - $base,
                    $random->getInt(intdiv($width, 2), $width + intdiv($width, 4)), $height,
                ], $color);
            }
        }

        // imagestring() font 5 is 9×15 px; scaled up, it becomes a soft initial.
        $glyph = imagecreatetruecolor(9, 15);
        imagealphablending($glyph, false);
        imagefilledrectangle($glyph, 0, 0, 8, 14, (int) imagecolorallocatealpha($glyph, 0, 0, 0, 127));
        imagestring($glyph, 5, 0, 0, \chr(65 + $number % 26), (int) imagecolorallocatealpha($glyph, 255, 255, 255, 30));
        $letterHeight = intdiv(min($width, $height), 2);
        $letterWidth = intdiv($letterHeight * 9, 15);
        imagealphablending($image, true);
        imagecopyresampled($image, $glyph, intdiv($width - $letterWidth, 2), intdiv($height - $letterHeight, 2), 0, 0, $letterWidth, $letterHeight, 9, 15);

        self::ensureDirectory(\dirname($file));
        imagejpeg($image, $file.'.tmp', 90);
        rename($file.'.tmp', $file);

        return $file;
    }

    /**
     * A diagonal gradient from top-left to bottom-right: drawn on a small canvas, scaled up smoothly.
     *
     * @param array{int, int, int} $from
     * @param array{int, int, int} $to
     */
    private static function gradient(\GdImage $image, array $from, array $to): void
    {
        $small = imagecreatetruecolor(32, 32);
        for ($x = 0; $x < 32; ++$x) {
            for ($y = 0; $y < 32; ++$y) {
                $t = ($x + $y) / 62;
                imagesetpixel($small, $x, $y, (int) imagecolorallocate(
                    $small,
                    self::channel($from[0] + ($to[0] - $from[0]) * $t),
                    self::channel($from[1] + ($to[1] - $from[1]) * $t),
                    self::channel($from[2] + ($to[2] - $from[2]) * $t),
                ));
            }
        }
        imagecopyresampled($image, $small, 0, 0, 0, 0, imagesx($image), imagesy($image), 32, 32);
    }

    /**
     * @return int<0, 255>
     */
    private static function channel(float $value): int
    {
        return max(0, min(255, (int) round($value)));
    }

    /**
     * @return array{int, int, int}
     */
    private static function hsl(int $hue, float $saturation, float $lightness): array
    {
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $x = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $lightness - $chroma / 2;
        [$r, $g, $b] = match (intdiv($hue, 60)) {
            0 => [$chroma, $x, 0.0],
            1 => [$x, $chroma, 0.0],
            2 => [0.0, $chroma, $x],
            3 => [0.0, $x, $chroma],
            4 => [$x, 0.0, $chroma],
            default => [$chroma, 0.0, $x],
        };

        return [(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)];
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create the directory "%s".', $directory));
        }
    }
}
