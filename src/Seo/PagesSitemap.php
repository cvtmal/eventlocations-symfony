<?php

declare(strict_types=1);

namespace App\Seo;

use App\Seo\ValueObject\SitemapUrl;
use App\Venues\ReadModel\VenueSitemapQuery;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Every URL of the sitemap that is not a venue. The one place #17 and #18 add their pages to.
 */
final class PagesSitemap
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly VenueSitemapQuery $venues,
    ) {
    }

    /**
     * @return list<SitemapUrl>
     */
    public function urls(): array
    {
        return [
            new SitemapUrl($this->urls->generate('listing', [], UrlGeneratorInterface::ABSOLUTE_URL), $this->venues->newestChange()),
        ];
    }
}
