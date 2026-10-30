<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Media\ValueObject\GeneratedImage;
use App\Media\ValueObject\ImageFormat;
use App\Media\ValueObject\ImageVariants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeneratedImageTest extends TestCase
{
    public function test_array_round_trip(): void
    {
        $image = new GeneratedImage(1600, 1067, '#b9a58c', new ImageVariants([360, 640], [ImageFormat::Webp, ImageFormat::Jpeg]));

        self::assertSame(
            ['width' => 1600, 'height' => 1067, 'dominantColor' => '#b9a58c', 'variants' => ['widths' => [360, 640], 'formats' => ['webp', 'jpg']]],
            $image->toArray(),
        );
        self::assertEquals($image, GeneratedImage::fromArray($image->toArray()));
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function invalid(): iterable
    {
        yield 'zero width' => [0, 100, '#b9a58c'];
        yield 'negative height' => [100, -1, '#b9a58c'];
        yield 'upper-case colour' => [100, 100, '#B9A58C'];
        yield 'colour without hash' => [100, 100, 'b9a58c'];
        yield 'short colour' => [100, 100, '#abc'];
    }

    #[DataProvider('invalid')]
    public function test_rejects_invalid_values(int $width, int $height, string $color): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GeneratedImage($width, $height, $color, new ImageVariants([100], [ImageFormat::Jpeg]));
    }

    public function test_rejects_an_incomplete_array(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GeneratedImage::fromArray(['width' => 10, 'height' => 10, 'dominantColor' => '#000000']);
    }
}
