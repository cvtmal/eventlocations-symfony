<?php

declare(strict_types=1);

namespace App\Seo;

use App\Search\LandingPaths;
use App\Search\ReadModel\LandingPage;
use App\Search\ReadModel\LandingPages;
use App\Search\ReadModel\LastMinutePageQuery;
use App\Search\ValueObject\LandingType;
use App\Seo\ValueObject\SitemapUrl;
use App\Venues\ReadModel\SitemapChanges;
use App\Venues\ReadModel\VenueSitemapQuery;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Every URL of the sitemap that is not a venue. The one place #17 and #18 add their pages to.
 * The home page (#36) comes first, so `bin/warm-cache` reaches it right after the listing; it
 * shows venues, so it shares the listing's `lastmod`.
 * Landing pages (#17) follow the listing: every live and indexable one, page 1, unfiltered, with the newest
 * change among its venues. The near-me page (#52) follows the landing pages with the listing's
 * `lastmod`, since it shows venues; «Kurzfristig frei» (G14) follows, without `lastmod`, while it lists an offer.
 * The legal pages (#18) come last, without `lastmod`: their text changes only with a deploy, and no date of it is
 * stored.
 */
final class PagesSitemap
{
    private const array LEGAL_ROUTES = ['impressum', 'datenschutz'];

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly VenueSitemapQuery $venues,
        private readonly LandingPages $landingPages,
        private readonly LandingPaths $landingPaths,
        private readonly LastMinutePageQuery $lastMinute,
    ) {
    }

    /**
     * @return list<SitemapUrl>
     */
    public function urls(): array
    {
        $newest = $this->venues->newestChange();
        $urls = [
            new SitemapUrl($this->urls->generate('home', [], UrlGeneratorInterface::ABSOLUTE_URL), $newest),
            new SitemapUrl($this->urls->generate('listing', [], UrlGeneratorInterface::ABSOLUTE_URL), $newest),
        ];

        // G12: a page that fails LandingQualityGate stays out (it is noindex).
        $landings = array_filter($this->landingPages->all(), static fn (LandingPage $page): bool => $page->indexable);
        if ([] !== $landings) {
            $changes = $this->venues->newestChangeByGroup();
            foreach ($landings as $page) {
                $urls[] = new SitemapUrl(
                    $this->landingPaths->path($page, [], UrlGeneratorInterface::ABSOLUTE_URL),
                    $this->lastmod($page, $changes),
                );
            }
        }

        $urls[] = new SitemapUrl($this->urls->generate('nearby', [], UrlGeneratorInterface::ABSOLUTE_URL), $newest);
        // G14: «Kurzfristig frei» only while it lists an offer (empty, it is noindex), without lastmod.
        if ($this->lastMinute->hasAny()) {
            $urls[] = new SitemapUrl($this->urls->generate('last_minute', [], UrlGeneratorInterface::ABSOLUTE_URL), null);
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
            LandingType::Event => null === $page->canton
                ? $changes->events[$page->event ?? ''] ?? null
                : $changes->eventCantons[$page->event.'.'.$page->canton->value] ?? null,
        };
    }
}
