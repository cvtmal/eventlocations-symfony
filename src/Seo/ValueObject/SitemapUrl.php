<?php

declare(strict_types=1);

namespace App\Seo\ValueObject;

/**
 * One `<url>` of a sitemap. All URLs are absolute.
 */
final readonly class SitemapUrl
{
    /**
     * @param list<string> $images
     */
    public function __construct(
        public string $loc,
        public ?\DateTimeImmutable $lastmod,
        public array $images = [],
    ) {
    }
}
