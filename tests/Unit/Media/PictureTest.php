<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Media\ValueObject\ImageFormat;
use App\Media\ValueObject\ImageVariants;
use App\Media\ValueObject\Picture;
use PHPUnit\Framework\TestCase;

final class PictureTest extends TestCase
{
    public function test_url_follows_the_s9_scheme(): void
    {
        $picture = new Picture(7, '019a0000-0000-7000-8000-000000000401', 1600, 1067, '#b9a58c', new ImageVariants([360, 640], [ImageFormat::Avif, ImageFormat::Jpeg]), 'Saal');

        self::assertSame('/media/7/019a0000-0000-7000-8000-000000000401-640.avif', $picture->url(640, ImageFormat::Avif));
        self::assertSame('/media/7/019a0000-0000-7000-8000-000000000401-360.jpg', $picture->url(360, ImageFormat::Jpeg));
    }

    public function test_share_image_is_a_jpeg_with_the_scaled_height(): void
    {
        $picture = new Picture(7, 'photo', 1600, 1067, '#b9a58c', new ImageVariants([360, 640, 960, 1280], [ImageFormat::Avif, ImageFormat::Jpeg]), 'Saal');

        $image = $picture->shareImage();

        self::assertSame('/media/7/photo-1280.jpg', $image->url);
        self::assertSame(1280, $image->width);
        self::assertSame(854, $image->height);
    }

    public function test_rejects_an_invalid_colour(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Picture(7, 'id', 100, 100, 'red', new ImageVariants([100], [ImageFormat::Jpeg]), 'Saal');
    }

    public function test_rejects_a_zero_size(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Picture(7, 'id', 0, 100, '#000000', new ImageVariants([100], [ImageFormat::Jpeg]), 'Saal');
    }
}
