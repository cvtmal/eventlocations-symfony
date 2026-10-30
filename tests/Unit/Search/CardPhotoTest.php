<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\ReadModel\CardPhoto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CardPhotoTest extends TestCase
{
    private const ID = '0198a3c4-6b1e-7f3a-9c2d-1e4f5a6b7c8d';
    private const VARIANTS = ['widths' => [360, 640, 960, 1280], 'formats' => ['avif', 'webp', 'jpg']];

    public function test_a_row_without_variant_columns(): void
    {
        $photo = CardPhoto::fromRow(42, $this->row(), null);

        self::assertSame(42, $photo->venueNumber);
        self::assertSame(self::ID, $photo->photoId);
        self::assertSame(3, $photo->position);
        self::assertSame('demo/seehaus.jpg', $photo->originalPath);
        self::assertNull($photo->width);
        self::assertNull($photo->height);
        self::assertNull($photo->dominantColor);
        self::assertNull($photo->variants);
        self::assertNull($photo->alt);
        self::assertFalse($photo->hasVariants());
    }

    public function test_a_row_with_the_media_columns(): void
    {
        $photo = CardPhoto::fromRow(42, $this->row([
            'width' => 1600,
            'height' => 1067,
            'dominant_color' => '#b9a58c',
            'variants' => json_decode('{"widths":[360,640,960,1280],"formats":["avif","webp","jpg"]}', true),
        ]), 'Seehaus am Abend');

        self::assertSame(1600, $photo->width);
        self::assertSame(1067, $photo->height);
        self::assertSame('#b9a58c', $photo->dominantColor);
        self::assertSame(self::VARIANTS, $photo->variants);
        self::assertSame('Seehaus am Abend', $photo->alt);
        self::assertTrue($photo->hasVariants());
    }

    public function test_extra_variant_keys_are_dropped(): void
    {
        $photo = CardPhoto::fromRow(1, $this->row(['width' => 1, 'height' => 1, 'dominant_color' => '#000000', 'variants' => ['formats' => ['jpg'], 'widths' => [360], 'extra' => true]]), null);

        self::assertSame(['widths' => [360], 'formats' => ['jpg']], $photo->variants);
    }

    /**
     * @param array<string, mixed> $columns
     */
    #[DataProvider('unusableVariants')]
    public function test_other_shapes_give_no_variants(array $columns): void
    {
        $photo = CardPhoto::fromRow(1, $this->row(['width' => 1600, 'height' => 1067, 'dominant_color' => '#b9a58c'] + $columns), null);

        self::assertFalse($photo->hasVariants());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusableVariants(): iterable
    {
        yield 'variants null' => [['variants' => null]];
        yield 'variants absent' => [[]];
        yield 'variants as a list (old spec shape)' => [['variants' => [['width' => 360, 'format' => 'jpg']]]];
        yield 'widths missing' => [['variants' => ['formats' => ['jpg']]]];
        yield 'widths not ints' => [['variants' => ['widths' => ['360'], 'formats' => ['jpg']]]];
        yield 'formats not a list' => [['variants' => ['widths' => [360], 'formats' => ['a' => 'jpg']]]];
    }

    public function test_wrong_types_are_null(): void
    {
        $photo = CardPhoto::fromRow(1, $this->row(['width' => '640', 'height' => 480.0, 'dominant_color' => 7, 'variants' => self::VARIANTS]), null);

        self::assertNull($photo->width);
        self::assertNull($photo->height);
        self::assertNull($photo->dominantColor);
        self::assertSame(self::VARIANTS, $photo->variants);
        self::assertFalse($photo->hasVariants());
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function row(array $extra = []): array
    {
        return ['id' => self::ID, 'venue_id' => self::ID, 'room_id' => null, 'position' => 3, 'original_path' => 'demo/seehaus.jpg'] + $extra;
    }
}
