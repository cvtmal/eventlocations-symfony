<?php

declare(strict_types=1);

namespace App\Media\ValueObject;

/**
 * What ImageVariantGenerator produced for one original: the EXIF-oriented original's size,
 * its average colour (the placeholder) and the variants written.
 */
final readonly class GeneratedImage
{
    /**
     * @throws \InvalidArgumentException on a non-positive size or a colour other than `#rrggbb` (lower-case)
     */
    public function __construct(
        public int $width,
        public int $height,
        public string $dominantColor,
        public ImageVariants $variants,
    ) {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException(\sprintf('An image is at least 1×1 pixels, got %d×%d.', $width, $height));
        }
        self::assertColor($dominantColor);
    }

    /**
     * @param array<mixed> $data the shape of toArray()
     *
     * @throws \InvalidArgumentException on any other shape
     */
    public static function fromArray(array $data): self
    {
        $variants = $data['variants'] ?? null;
        if (!\is_int($data['width'] ?? null) || !\is_int($data['height'] ?? null) || !\is_string($data['dominantColor'] ?? null) || !\is_array($variants)) {
            throw new \InvalidArgumentException('A generated image needs width, height, dominantColor and variants.');
        }

        return new self($data['width'], $data['height'], $data['dominantColor'], ImageVariants::fromArray($variants));
    }

    /**
     * @return array{width: int, height: int, dominantColor: string, variants: array{widths: list<int>, formats: list<string>}}
     */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'dominantColor' => $this->dominantColor,
            'variants' => $this->variants->toArray(),
        ];
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function assertColor(string $color): void
    {
        if (1 !== preg_match('/^#[0-9a-f]{6}$/', $color)) {
            throw new \InvalidArgumentException(\sprintf('A colour is "#rrggbb" in lower case, got "%s".', $color));
        }
    }
}
