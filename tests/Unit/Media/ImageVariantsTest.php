<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Media\ValueObject\ImageFormat;
use App\Media\ValueObject\ImageVariants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageVariantsTest extends TestCase
{
    public function test_json_round_trip(): void
    {
        $variants = ImageVariants::fromJson('{"widths": [360, 640, 960, 1280], "formats": ["avif", "webp", "jpg"]}');

        self::assertSame([360, 640, 960, 1280], $variants->widths);
        self::assertSame([ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg], $variants->formats);
        self::assertSame(['widths' => [360, 640, 960, 1280], 'formats' => ['avif', 'webp', 'jpg']], $variants->toArray());
        self::assertEquals($variants, ImageVariants::fromArray($variants->toArray()));
        self::assertSame(360, $variants->smallest());
        self::assertSame(1280, $variants->largest());
    }

    public function test_formats_are_sorted_into_preference_order(): void
    {
        $variants = new ImageVariants([300], [ImageFormat::Jpeg, ImageFormat::Webp]);

        self::assertSame(['webp', 'jpg'], $variants->toArray()['formats']);
        self::assertTrue($variants->has(ImageFormat::Webp));
        self::assertFalse($variants->has(ImageFormat::Avif));
        self::assertSame(300, $variants->smallest());
        self::assertSame(300, $variants->largest());
    }

    /**
     * @return iterable<string, array{list<int>, int}>
     */
    public static function shareWidths(): iterable
    {
        yield 'a full photo' => [[360, 640, 960, 1280, 1920], 1280];
        yield 'a photo below the wanted width' => [[360, 640, 960], 960];
        yield 'exactly the wanted width' => [[1200], 1200];
    }

    /**
     * @param list<int> $widths
     */
    #[DataProvider('shareWidths')]
    public function test_share_width_is_the_smallest_big_enough_else_the_largest(array $widths, int $expected): void
    {
        self::assertSame($expected, new ImageVariants($widths, [ImageFormat::Jpeg])->shareWidth());
    }

    /**
     * @return iterable<string, array{list<int>, list<ImageFormat>}>
     */
    public static function invalid(): iterable
    {
        yield 'no widths' => [[], [ImageFormat::Jpeg]];
        yield 'unsorted widths' => [[640, 360], [ImageFormat::Jpeg]];
        yield 'duplicate width' => [[360, 360], [ImageFormat::Jpeg]];
        yield 'zero width' => [[0, 360], [ImageFormat::Jpeg]];
        yield 'no jpeg' => [[360], [ImageFormat::Avif, ImageFormat::Webp]];
        yield 'duplicate format' => [[360], [ImageFormat::Jpeg, ImageFormat::Jpeg]];
    }

    /**
     * @param list<int>         $widths
     * @param list<ImageFormat> $formats
     */
    #[DataProvider('invalid')]
    public function test_rejects_invalid_lists(array $widths, array $formats): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ImageVariants($widths, $formats);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidJson(): iterable
    {
        yield 'not json' => ['{widths'];
        yield 'a list' => ['[360]'];
        yield 'no formats' => ['{"widths": [360]}'];
        yield 'unknown format' => ['{"widths": [360], "formats": ["gif", "jpg"]}'];
        yield 'width as string' => ['{"widths": ["360"], "formats": ["jpg"]}'];
        yield 'widths as object' => ['{"widths": {"a": 360}, "formats": ["jpg"]}'];
    }

    #[DataProvider('invalidJson')]
    public function test_rejects_invalid_json(string $json): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ImageVariants::fromJson($json);
    }
}
