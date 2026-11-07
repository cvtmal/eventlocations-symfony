<?php

declare(strict_types=1);

namespace App\Venues\SiteImport;

use App\Venues\ValueObject\ImageCandidate;

/**
 * One page, read: its facts, the image references on it, the links worth following, and how many image references
 * were left out as icons, logos or tiny pictures.
 */
final readonly class ParsedPage
{
    /**
     * @param list<ImageCandidate> $images
     * @param list<PageLink>       $links
     */
    public function __construct(
        public FactSet $facts,
        public array $images,
        public array $links,
        public int $skippedImages,
    ) {
    }
}
