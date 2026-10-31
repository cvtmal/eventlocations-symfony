<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

/**
 * One photo in the photo manager (#25, D12, PF2): where it sits, its thumbnail, its alt texts and
 * its row token. `roomId` null is the gallery; gallery position 1 is the cover.
 */
final readonly class ManagedPhoto
{
    /**
     * @param list<int>             $widths  the variant widths that exist
     * @param list<string>          $formats the variant formats that exist (`avif`, `webp`, `jpg`)
     * @param array<string, string> $alts    locale → alt text, only the locales with a row
     * @param string                $token   the row token (`RowTokens::sql()` of `venue_photos.updated_at`)
     */
    public function __construct(
        public string $id,
        public ?string $roomId,
        public int $position,
        public string $thumbnailUrl,
        public int $width,
        public int $height,
        public string $dominantColor,
        public array $widths,
        public array $formats,
        public array $alts,
        public string $token,
    ) {
    }

    /**
     * The island's props and the JSON answers of the photo endpoints (PF10).
     *
     * @return array{id: string, roomId: ?string, position: int, thumbnailUrl: string, width: int, height: int, dominantColor: string, widths: list<int>, formats: list<string>, alts: array<string, string>, token: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'roomId' => $this->roomId,
            'position' => $this->position,
            'thumbnailUrl' => $this->thumbnailUrl,
            'width' => $this->width,
            'height' => $this->height,
            'dominantColor' => $this->dominantColor,
            'widths' => $this->widths,
            'formats' => $this->formats,
            'alts' => $this->alts,
            'token' => $this->token,
        ];
    }
}
