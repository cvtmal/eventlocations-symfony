<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Media\ImageVariantGenerator;
use App\Tests\Support\Media\TestImages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SwapsAxesTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->files);
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function orientations(): iterable
    {
        yield 'upright' => [1, false];
        yield 'upside down keeps the axes' => [3, false];
        yield 'the first turned value' => [5, true];
        yield 'a quarter, as a phone stores it' => [6, true];
        yield 'the last turned value' => [8, true];
    }

    #[DataProvider('orientations')]
    public function test_a_jpeg_swaps_its_axes_when_exif_turns_it(int $orientation, bool $swaps): void
    {
        self::assertSame($swaps, ImageVariantGenerator::swapsAxes($this->file(TestImages::withOrientation(TestImages::jpeg(), $orientation))));
    }

    public function test_a_jpeg_without_exif_keeps_its_axes(): void
    {
        self::assertFalse(ImageVariantGenerator::swapsAxes($this->file(TestImages::jpeg())));
    }

    public function test_a_png_keeps_its_axes(): void
    {
        self::assertFalse(ImageVariantGenerator::swapsAxes($this->file(TestImages::transparentPng())));
    }

    public function test_a_missing_file_keeps_its_axes(): void
    {
        self::assertFalse(ImageVariantGenerator::swapsAxes(sys_get_temp_dir().'/does-not-exist.jpg'));
    }

    private function file(string $bytes): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'axes');
        file_put_contents($file, $bytes);
        $this->files[] = $file;

        return $file;
    }
}
