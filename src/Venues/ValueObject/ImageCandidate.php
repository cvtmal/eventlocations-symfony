<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * An image reference found on a page of a venue's website, with the page it was found on. Dimensions are the ones the
 * page declares (attributes, srcset descriptors); nothing was downloaded to measure them.
 */
final readonly class ImageCandidate
{
    public function __construct(
        public string $imageUrl,
        public string $pageUrl,
        public CandidateOrigin $origin,
        public ?string $alt = null,
        public ?string $context = null,
        public ?int $width = null,
        public ?int $height = null,
    ) {
    }
}
