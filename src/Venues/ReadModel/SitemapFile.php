<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

/**
 * One `sitemap-venues-{number}.xml`, with the newest change among its venues.
 */
final readonly class SitemapFile
{
    public function __construct(
        public int $number,
        public \DateTimeImmutable $lastmod,
    ) {
    }
}
