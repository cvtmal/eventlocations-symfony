<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media;

use App\Media\Exception\MissingOriginal;
use App\Media\Exception\UnreadableImage;
use App\Media\ImageVariantGenerator;
use App\Media\ValueObject\ImageFormat;
use App\Tests\Support\Media\MediaFiles;
use App\Tests\Support\Media\TestImages;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The gd generator in our PHP image: widths, sizes, formats, orientation, stripping, alpha,
 * fallback, stale files, errors, colour and memory (S9, #5 "Done when").
 */
final class ImageVariantGeneratorTest extends KernelTestCase
{
    private string $directory;
    private ImageVariantGenerator $generator;

    protected function setUp(): void
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        \assert(\is_string($projectDir));
        $this->directory = $projectDir.'/var/test-media/tmp-'.uniqid();
        $generator = self::getContainer()->get(ImageVariantGenerator::class);
        self::assertInstanceOf(ImageVariantGenerator::class, $generator);
        $this->generator = $generator;
    }

    protected function tearDown(): void
    {
        MediaFiles::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function test_our_image_supports_avif_webp_and_jpeg(): void
    {
        self::assertSame([ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg], ImageVariantGenerator::availableFormats());
        self::assertSame([ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg], $this->generator->formats());
    }

    public function test_a_large_original_gets_every_width_in_every_format(): void
    {
        $image = $this->generator->generate($this->original('large.jpg', TestImages::jpeg(2400, 1600)), $this->out(), 'photo');

        self::assertSame(2400, $image->width);
        self::assertSame(1600, $image->height);
        self::assertSame([360, 640, 960, 1280, 1920], $image->variants->widths);
        self::assertSame([ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg], $image->variants->formats);
        foreach ($image->variants->widths as $width) {
            foreach (ImageFormat::cases() as $format) {
                $file = \sprintf('%s/photo-%d.%s', $this->out(), $width, $format->value);
                self::assertFileExists($file);
                self::assertSame([$width, (int) round($width * 1600 / 2400)], MediaFiles::size($file), basename($file));
                self::assertSame($format->mimeType(), mime_content_type($file), basename($file));
            }
        }
        self::assertCount(15, glob($this->out().'/*') ?: []);
    }

    /**
     * @return iterable<string, array{int<1, max>, int<1, max>, list<int>}>
     */
    public static function noUpscaling(): iterable
    {
        yield '1000 px' => [1000, 700, [360, 640, 960]];
        yield 'exactly 1920 px' => [1920, 1080, [360, 640, 960, 1280, 1920]];
        yield 'narrower than 360 px' => [300, 200, [300]];
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     * @param list<int>   $widths
     */
    #[DataProvider('noUpscaling')]
    public function test_never_upscales(int $width, int $height, array $widths): void
    {
        $image = $this->generator->generate($this->original('small.jpg', TestImages::jpeg($width, $height)), $this->out(), 'p');

        self::assertSame($widths, $image->variants->widths);
        self::assertSame([$width, $height], [$image->width, $image->height]);
        $largest = $widths[\count($widths) - 1];
        self::assertSame([$largest, (int) round($largest * $height / $width)], MediaFiles::size($this->out().'/p-'.$largest.'.jpg'));
    }

    /**
     * Stored 800×400: left red, right blue, green top-left. Where everything lands upright.
     *
     * @return iterable<string, array{int, array{int, int}, list<int>, array{float, float}, array{float, float}, string}>
     */
    public static function orientations(): iterable
    {
        yield '1 as stored' => [1, [800, 400], [360, 640], [0.25, 0.75], [0.75, 0.75], 'top-left'];
        yield '2 mirrored' => [2, [800, 400], [360, 640], [0.75, 0.75], [0.25, 0.75], 'top-right'];
        yield '3 upside down' => [3, [800, 400], [360, 640], [0.75, 0.25], [0.25, 0.25], 'bottom-right'];
        yield '6 turned right' => [6, [400, 800], [360], [0.5, 0.25], [0.5, 0.75], 'top-right'];
        yield '8 turned left' => [8, [400, 800], [360], [0.5, 0.75], [0.5, 0.25], 'bottom-left'];
    }

    /**
     * @param array{int, int}     $size
     * @param list<int>           $widths
     * @param array{float, float} $red
     * @param array{float, float} $blue
     */
    #[DataProvider('orientations')]
    public function test_exif_orientation_is_applied(int $orientation, array $size, array $widths, array $red, array $blue, string $marker): void
    {
        $source = $this->original('o.jpg', TestImages::withOrientation(TestImages::jpeg(800, 400), $orientation));

        $image = $this->generator->generate($source, $this->out(), 'o');

        self::assertSame($size, [$image->width, $image->height]);
        self::assertSame($widths, $image->variants->widths);
        $expectedSize = [360, (int) round(360 * $size[1] / $size[0])];
        foreach ([ImageFormat::Jpeg, ImageFormat::Webp, ImageFormat::Avif] as $format) {
            $file = $this->out().'/o-360.'.$format->value;
            self::assertSame($expectedSize, MediaFiles::size($file));
            $pixels = MediaFiles::image($file);
            [$w, $h] = $expectedSize;
            self::assertSame('red', TestImages::colorNameAt($pixels, (int) ($red[0] * $w), (int) ($red[1] * $h)), $format->value);
            self::assertSame('blue', TestImages::colorNameAt($pixels, (int) ($blue[0] * $w), (int) ($blue[1] * $h)), $format->value);
            $d = intdiv(min($w, $h), 10);
            [$x, $y] = match ($marker) {
                'top-left' => [$d, $d],
                'top-right' => [$w - 1 - $d, $d],
                'bottom-left' => [$d, $h - 1 - $d],
                default => [$w - 1 - $d, $h - 1 - $d],
            };
            self::assertSame('green', TestImages::colorNameAt($pixels, $x, $y), $format->value.' marker '.$marker);
        }
    }

    public function test_output_carries_no_metadata(): void
    {
        $source = $this->original('gps.jpg', TestImages::withOrientationAndGps(TestImages::jpeg(800, 400), 6));
        $input = exif_read_data($source);
        self::assertIsArray($input);
        self::assertSame(6, $input['Orientation']);
        self::assertSame('N', $input['GPSLatitudeRef']);

        $this->generator->generate($source, $this->out(), 'g');

        $exif = @exif_read_data($this->out().'/g-360.jpg');
        self::assertIsArray($exif);
        self::assertArrayNotHasKey('Orientation', $exif);
        self::assertArrayNotHasKey('GPSLatitudeRef', $exif);
        self::assertIsString($exif['SectionsFound']);
        self::assertStringNotContainsString('IFD0', $exif['SectionsFound']);
        self::assertStringNotContainsString('GPS', $exif['SectionsFound']);
    }

    public function test_transparency_becomes_white(): void
    {
        $this->generator->generate($this->original('alpha.png', TestImages::transparentPng(800, 400)), $this->out(), 'a');

        foreach (ImageFormat::cases() as $format) {
            $pixels = MediaFiles::image($this->out().'/a-640.'.$format->value);
            self::assertSame('white', TestImages::colorNameAt($pixels, 160, 160), $format->value);
            self::assertSame('blue', TestImages::colorNameAt($pixels, 480, 160), $format->value);
            [, , , $alpha] = array_values(imagecolorsforindex($pixels, (int) imagecolorat($pixels, 160, 160)));
            self::assertSame(0, $alpha, $format->value);
        }
    }

    public function test_without_avif_only_webp_and_jpeg_are_written(): void
    {
        $generator = new ImageVariantGenerator([ImageFormat::Webp, ImageFormat::Jpeg]);

        $image = $generator->generate($this->original('f.jpg', TestImages::jpeg(1000, 700)), $this->out(), 'f');

        self::assertSame(['widths' => [360, 640, 960], 'formats' => ['webp', 'jpg']], $image->variants->toArray());
        self::assertSame([], glob($this->out().'/*.avif'));
        self::assertCount(6, glob($this->out().'/f-*') ?: []);
        self::assertNotSame($this->generator->fingerprint(), $generator->fingerprint());
    }

    public function test_regenerating_a_smaller_original_removes_the_stale_widths(): void
    {
        file_put_contents($this->out(true).'/other-1920.jpg', 'another photo');
        $this->generator->generate($this->original('big.jpg', TestImages::jpeg(2400, 1600)), $this->out(), 'r');
        self::assertFileExists($this->out().'/r-1920.avif');

        $image = $this->generator->generate($this->original('small.jpg', TestImages::jpeg(700, 500)), $this->out(), 'r');

        self::assertSame([360, 640], $image->variants->widths);
        $names = array_map('basename', glob($this->out().'/r-*') ?: []);
        sort($names);
        self::assertSame(['r-360.avif', 'r-360.jpg', 'r-360.webp', 'r-640.avif', 'r-640.jpg', 'r-640.webp'], $names);
        self::assertFileExists($this->out().'/other-1920.jpg');
    }

    public function test_garbage_is_unreadable(): void
    {
        $this->expectException(UnreadableImage::class);

        $this->generator->generate($this->original('garbage.jpg', 'this is not an image'), $this->out(), 'x');
    }

    public function test_an_empty_file_is_unreadable(): void
    {
        $this->expectException(UnreadableImage::class);

        $this->generator->generate($this->original('empty.jpg', ''), $this->out(), 'x');
    }

    public function test_a_missing_file_is_reported(): void
    {
        $this->expectException(MissingOriginal::class);

        $this->generator->generate($this->directory.'/nope.jpg', $this->out(), 'x');
    }

    public function test_dominant_color_is_the_average_colour(): void
    {
        $image = $this->generator->generate($this->original('u.jpg', TestImages::uniformJpeg(800, 600, 0x33, 0x66, 0x99)), $this->out(), 'u');

        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $image->dominantColor);
        foreach ([[1, 0x33], [3, 0x66], [5, 0x99]] as [$offset, $expected]) {
            self::assertEqualsWithDelta($expected, hexdec(substr($image->dominantColor, $offset, 2)), 1);
        }
    }

    public function test_a_rotated_original_needs_one_full_size_bitmap(): void
    {
        $source = $this->original('huge.jpg', TestImages::withOrientation(TestImages::jpeg(4000, 3000), 6));
        $decodedBytes = 4000 * 3000 * 4;

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $image = $this->generator->generate($source, $this->out(), 'h');

        self::assertSame([3000, 4000], [$image->width, $image->height]);
        self::assertLessThan(1.75 * $decodedBytes, memory_get_peak_usage() - $before);
    }

    private function original(string $name, string $bytes): string
    {
        return TestImages::write($this->directory.'/src', $name, $bytes);
    }

    private function out(bool $create = false): string
    {
        $out = $this->directory.'/out';
        if ($create && !is_dir($out)) {
            mkdir($out, 0o775, true);
        }

        return $out;
    }
}
