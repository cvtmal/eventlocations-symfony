<?php

declare(strict_types=1);

namespace App\Media\ValueObject;

/**
 * Which variants of a photo exist: every width in every format. Stored as the JSONB
 * venue_photos.variants, `{"widths": [360, 640, 960], "formats": ["avif", "webp", "jpg"]}`.
 * JPEG is always there; AVIF and WebP only when the encoding host supported them.
 */
final readonly class ImageVariants
{
    private const int SHARE_WIDTH = 1200;

    /** @var list<int> ascending, unique, > 0 */
    public array $widths;

    /** @var list<ImageFormat> unique, in ImageFormat order, always with Jpeg */
    public array $formats;

    /**
     * @param list<int>         $widths
     * @param list<ImageFormat> $formats
     *
     * @throws \InvalidArgumentException when a list is empty, unsorted, duplicated or JPEG is missing
     */
    public function __construct(array $widths, array $formats)
    {
        if ([] === $widths) {
            throw new \InvalidArgumentException('An image needs at least one variant width.');
        }
        $previous = 0;
        foreach ($widths as $width) {
            if ($width <= $previous) {
                throw new \InvalidArgumentException(\sprintf('Variant widths must be positive and strictly ascending, got [%s].', implode(', ', $widths)));
            }
            $previous = $width;
        }

        $sorted = [];
        foreach (ImageFormat::cases() as $case) {
            $count = \count(array_filter($formats, static fn (ImageFormat $format): bool => $format === $case));
            if ($count > 1) {
                throw new \InvalidArgumentException(\sprintf('The format "%s" is listed twice.', $case->value));
            }
            if (1 === $count) {
                $sorted[] = $case;
            }
        }
        if (!\in_array(ImageFormat::Jpeg, $sorted, true)) {
            throw new \InvalidArgumentException('Every image has JPEG variants (the fallback of every browser).');
        }

        $this->widths = $widths;
        $this->formats = $sorted;
    }

    /**
     * @param array<mixed> $data the decoded JSONB, `{"widths": [...], "formats": [...]}`
     *
     * @throws \InvalidArgumentException on any other shape
     */
    public static function fromArray(array $data): self
    {
        $widths = $data['widths'] ?? null;
        $formats = $data['formats'] ?? null;
        if (!\is_array($widths) || !array_is_list($widths) || !\is_array($formats) || !array_is_list($formats)) {
            throw new \InvalidArgumentException('Image variants need the lists "widths" and "formats".');
        }

        return new self(
            array_map(static fn (mixed $width): int => \is_int($width) ? $width : throw new \InvalidArgumentException('Variant widths are integers.'), $widths),
            array_map(static fn (mixed $format): ImageFormat => (\is_string($format) ? ImageFormat::tryFrom($format) : null) ?? throw new \InvalidArgumentException(\sprintf('Unknown image format %s.', json_encode($format))), $formats),
        );
    }

    /**
     * @throws \InvalidArgumentException on invalid JSON or shape
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Image variants are not valid JSON.', 0, $exception);
        }
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('Image variants must be a JSON object.');
        }

        return self::fromArray($data);
    }

    /**
     * @return array{widths: list<int>, formats: list<string>}
     */
    public function toArray(): array
    {
        return [
            'widths' => $this->widths,
            'formats' => array_map(static fn (ImageFormat $format): string => $format->value, $this->formats),
        ];
    }

    public function has(ImageFormat $format): bool
    {
        return \in_array($format, $this->formats, true);
    }

    public function smallest(): int
    {
        return $this->widths[0];
    }

    public function largest(): int
    {
        return $this->widths[\count($this->widths) - 1];
    }

    /**
     * The width a share image uses: the smallest at least SHARE_WIDTH wide, else the largest.
     * S9 has no 1200 variant, so a full photo shares its 1280.
     */
    public function shareWidth(): int
    {
        foreach ($this->widths as $width) {
            if ($width >= self::SHARE_WIDTH) {
                return $width;
            }
        }

        return $this->largest();
    }
}
