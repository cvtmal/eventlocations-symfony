<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Media\ValueObject\Picture;

/**
 * One public photo of the complaint form (G2): the thumbnail, and its place for the checkbox's label (the gallery
 * position, or the room and the position in it). The picture carries no alt: the label names the photo.
 */
final readonly class ReportablePhoto
{
    public function __construct(
        public Picture $picture,
        public int $position,
        public ?string $roomName,
    ) {
    }
}
