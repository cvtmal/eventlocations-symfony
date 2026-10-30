<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

/**
 * One published venue in a sitemap file.
 */
final readonly class SitemapVenue
{
    /**
     * @param list<string> $imageUrls paths of the share images, venue-level photos first
     */
    public function __construct(
        public string $slug,
        public \DateTimeImmutable $lastmod,
        public array $imageUrls,
    ) {
    }
}
