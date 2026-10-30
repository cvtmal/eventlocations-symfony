<?php

declare(strict_types=1);

namespace App\Seo;

use App\Search\LandingPaths;
use App\Search\ReadModel\LandingPage;
use App\Search\ReadModel\LandingPages;
use App\Search\ValueObject\LandingType;
use App\Seo\ValueObject\SitemapUrl;
use App\Venues\ReadModel\SitemapChanges;
use App\Venues\ReadModel\VenueSitemapQuery;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Every URL of the sitemap that is not a venue. The one place #17 and #18 add their pages to.
 * Landing pages (#17) follow the listing: every live one, page 1, unfiltered, with the newest
 * change among its venues. The legal pages (#18) come last, without `lastmod`: their text changes
 * only with a deploy, and no date of it is stored.
 */
final class PagesSitemap
{
    private const array LEGAL_ROUTES = ['impressum', 'datenschutz'];

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly VenueSitemapQuery $venues,
        private readonly LandingPages $landingPages,
        private readonly LandingPaths $landingPaths,
    ) {
    }

    /**
     * @return list<SitemapUrl>
     */
    public function urls(): array
    {
        $urls = [
            new SitemapUrl($this->urls->generate('listing', [], UrlGeneratorInterface::ABSOLUTE_URL), $this->venues->newestChange()),
        ];

        $landings = $this->landingPages->all();
        if ([] !== $landings) {
            $changes = $this->venues->newestChangeByGroup();
            foreach ($landings as $page) {
                $urls[] = new SitemapUrl(
                    $this->landingPaths->path($page, [], UrlGeneratorInterface::ABSOLUTE_URL),
                    $this->lastmod($page, $changes),
                );
            }
        }

        foreach (self::LEGAL_ROUTES as $route) {
            $urls[] = new SitemapUrl($this->urls->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL), null);
        }

        return $urls;
    }

    private function lastmod(LandingPage $page, SitemapChanges $changes): ?\DateTimeImmutable
    {
        return match ($page->type) {
            LandingType::Canton => $changes->cantons[$page->canton->value ?? ''] ?? null,
            LandingType::Town => $changes->towns[$page->townId ?? ''] ?? null,
            LandingType::Building => $changes->buildings[$page->building ?? ''] ?? null,
        };
    }
}
