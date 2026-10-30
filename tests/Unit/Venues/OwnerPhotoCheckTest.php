<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Tests\Support\Media\MediaFiles;
use App\Tests\Support\Media\TestImages;
use App\Venues\OwnerPhotoCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OwnerPhotoCheckTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \dirname(__DIR__, 3).'/var/test-owner-photo-check-'.uniqid();
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        MediaFiles::removeDirectory($this->directory);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function importable(): iterable
    {
        yield 'JPEG' => [TestImages::jpeg(120, 80)];
        yield 'PNG' => [TestImages::transparentPng(120, 80)];
        $image = imagecreatetruecolor(120, 80);
        ob_start();
        imagewebp($image);
        yield 'WebP' => [(string) ob_get_clean()];
    }

    #[DataProvider('importable')]
    public function test_jpeg_png_and_webp_pass(string $bytes): void
    {
        $seen = [];

        self::assertNull(OwnerPhotoCheck::verdict($this->file('photo', $bytes), $seen));
        self::assertCount(1, $seen);
    }

    public function test_a_real_avif_named_jpg_is_rejected(): void
    {
        $seen = [];

        self::assertSame(OwnerPhotoCheck::NOT_AN_IMAGE, OwnerPhotoCheck::verdict($this->file('cover.jpg', TestImages::avif()), $seen));
        self::assertSame(OwnerPhotoCheck::NOT_AN_IMAGE, OwnerPhotoCheck::verdict($this->file('text.jpg', 'not an image'), $seen));
        self::assertSame([], $seen);
    }

    public function test_a_png_header_with_garbage_after_it_is_rejected(): void
    {
        $seen = [];

        self::assertSame(OwnerPhotoCheck::UNDECODABLE, OwnerPhotoCheck::verdict($this->file('broken.png', TestImages::pngHeader(40, 30)), $seen));
    }

    public function test_a_header_above_the_ceiling_is_rejected_without_decoding(): void
    {
        $seen = [];
        $started = hrtime(true);

        // 144 MP declared: a decode would need far more than this test's memory limit.
        self::assertSame('above 100 megapixels', OwnerPhotoCheck::verdict($this->file('huge.png', TestImages::pngHeader(12_000, 12_000)), $seen));
        self::assertLessThan(500_000_000, hrtime(true) - $started);
    }

    public function test_the_same_bytes_twice_are_rejected_and_the_first_wins(): void
    {
        $seen = [];
        $bytes = TestImages::jpeg(120, 80);

        self::assertNull(OwnerPhotoCheck::verdict($this->file('first.jpg', $bytes), $seen));
        self::assertSame(OwnerPhotoCheck::DUPLICATE, OwnerPhotoCheck::verdict($this->file('second.jpg', $bytes), $seen));
        self::assertNull(OwnerPhotoCheck::verdict($this->file('third.jpg', TestImages::jpeg(121, 80)), $seen));
    }

    public function test_an_animated_webp_cannot_be_decoded(): void
    {
        $seen = [];
        $path = $this->file('animated.webp', TestImages::animatedWebp());

        // getimagesize() reads it as a WebP, so only gd's decoder catches it (Probe 0.4).
        self::assertSame(\IMAGETYPE_WEBP, (getimagesize($path) ?: [2 => null])[2]);
        self::assertSame(OwnerPhotoCheck::UNDECODABLE, OwnerPhotoCheck::verdict($path, $seen));
    }

    private function file(string $name, string $bytes): string
    {
        file_put_contents($this->directory.'/'.$name, $bytes);

        return $this->directory.'/'.$name;
    }
}
