<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

/**
 * One photo of a listing card (or of a similar-venue card), read from `to_jsonb(venue_photos)`
 * so that columns added to venue_photos appear without an SQL change.
 *
 * The variant fields (width, height, dominantColor, variants) carry exactly what
 * App\Media\ValueObject\Picture needs. They are null while the row has none (before the
 * media cycle's columns exist, or before a photo is processed); hasVariants() says whether
 * a Picture can be built:
 *
 *     new Picture($p->venueNumber, $p->photoId, $p->width, $p->height, $p->dominantColor,
 *         ImageVariants::fromArray($p->variants), $p->alt ?? <picture.alt_fallback, media>)
 *
 * This class deliberately does not reference the Media classes.
 */
final readonly class CardPhoto
{
    /**
     * @param array{widths: list<int>, formats: list<string>}|null $variants
     */
    private function __construct(
        public int $venueNumber,
        public string $photoId,
        public int $position,
        public string $originalPath,
        public ?int $width,
        public ?int $height,
        public ?string $dominantColor,
        public ?array $variants,
        public ?string $alt,
    ) {
    }

    /**
     * @param array<mixed> $photo the decoded `to_jsonb()` of a venue_photos row
     * @param string|null  $alt   the localized venue_photo_translations.alt, null when none
     */
    public static function fromRow(int $venueNumber, array $photo, ?string $alt): self
    {
        \assert(\is_string($photo['id']));
        \assert(\is_int($photo['position']));
        \assert(\is_string($photo['original_path']));

        $width = $photo['width'] ?? null;
        $height = $photo['height'] ?? null;
        $colour = $photo['dominant_color'] ?? null;

        return new self(
            $venueNumber,
            $photo['id'],
            $photo['position'],
            $photo['original_path'],
            \is_int($width) ? $width : null,
            \is_int($height) ? $height : null,
            \is_string($colour) ? $colour : null,
            self::variants($photo['variants'] ?? null),
            $alt,
        );
    }

    public function hasVariants(): bool
    {
        return null !== $this->width && null !== $this->height && null !== $this->dominantColor && null !== $this->variants;
    }

    /**
     * Only the media cycle's object shape {"widths": [...], "formats": [...]} is accepted.
     *
     * @return array{widths: list<int>, formats: list<string>}|null
     */
    private static function variants(mixed $variants): ?array
    {
        if (!\is_array($variants) || array_is_list($variants)) {
            return null;
        }
        $widths = $variants['widths'] ?? null;
        $formats = $variants['formats'] ?? null;
        if (!\is_array($widths) || !array_is_list($widths) || !\is_array($formats) || !array_is_list($formats)) {
            return null;
        }
        $intWidths = array_values(array_filter($widths, \is_int(...)));
        $stringFormats = array_values(array_filter($formats, \is_string(...)));
        if (\count($intWidths) !== \count($widths) || \count($stringFormats) !== \count($formats)) {
            return null;
        }

        return ['widths' => $intWidths, 'formats' => $stringFormats];
    }
}
