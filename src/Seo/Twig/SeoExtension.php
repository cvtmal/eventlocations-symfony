<?php

declare(strict_types=1);

namespace App\Seo\Twig;

use App\Search\ReadModel\ListingPage;
use App\Search\ReadModel\SearchCriteria;
use App\Seo\BreadcrumbJsonLd;
use App\Seo\JsonLdScript;
use App\Seo\ListingJsonLd;
use App\Seo\ListingMeta;
use App\Seo\SiteJsonLd;
use App\Seo\ValueObject\PageMeta;
use App\Seo\ValueObject\VenueFacts;
use App\Seo\VenueJsonLd;
use App\Seo\VenueMeta;
use App\Venues\ReadModel\VenueDetail;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The seam between pages and Seo. Search and Venues never depend on Seo in PHP: their templates
 * call these functions.
 *
 * - `listing_seo(criteria, page)`, `venue_seo(venue)`: the PageMeta for `seo/_meta.html.twig`.
 * - `site_jsonld()`, `venue_jsonld(venue)`, `breadcrumb_jsonld(items)`, `listing_jsonld(page)`:
 *   a ready script block; `listing_jsonld` is empty for a page without cards.
 */
final class SeoExtension
{
    private const int JSON_LD_IMAGES = 3;

    public function __construct(
        private readonly ListingMeta $listingMeta,
        private readonly VenueMeta $venueMeta,
        private readonly SiteJsonLd $siteJsonLd,
        private readonly VenueJsonLd $venueJsonLd,
        private readonly ListingJsonLd $listingJsonLd,
        private readonly UrlGeneratorInterface $urls,
        private readonly UrlHelper $urlHelper,
    ) {
    }

    #[AsTwigFunction('listing_seo')]
    public function listingSeo(SearchCriteria $criteria, ListingPage $page): PageMeta
    {
        return $this->listingMeta->for($criteria, $page);
    }

    #[AsTwigFunction('venue_seo')]
    public function venueSeo(VenueDetail $venue): PageMeta
    {
        return $this->venueMeta->for($this->facts($venue));
    }

    #[AsTwigFunction('site_jsonld', isSafe: ['html'])]
    public function siteJsonLd(): string
    {
        return JsonLdScript::render($this->siteJsonLd->build());
    }

    #[AsTwigFunction('venue_jsonld', isSafe: ['html'])]
    public function venueJsonLd(VenueDetail $venue): string
    {
        return JsonLdScript::render($this->venueJsonLd->build($this->facts($venue)));
    }

    /**
     * @param list<array{name: string, url?: string}> $items
     */
    #[AsTwigFunction('breadcrumb_jsonld', isSafe: ['html'])]
    public function breadcrumbJsonLd(array $items): string
    {
        return JsonLdScript::render(BreadcrumbJsonLd::build($items));
    }

    #[AsTwigFunction('listing_jsonld', isSafe: ['html'])]
    public function listingJsonLd(ListingPage $page): string
    {
        $data = $this->listingJsonLd->build($page);

        return null === $data ? '' : JsonLdScript::render($data);
    }

    private function facts(VenueDetail $venue): VenueFacts
    {
        return VenueFacts::fromDetail(
            $venue,
            $this->urls->generate('venue_show', ['slug' => $venue->slug], UrlGeneratorInterface::ABSOLUTE_URL),
            array_map($this->urlHelper->getAbsoluteUrl(...), $venue->imageUrls(self::JSON_LD_IMAGES)),
        );
    }
}
