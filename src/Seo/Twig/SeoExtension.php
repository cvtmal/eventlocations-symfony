<?php

declare(strict_types=1);

namespace App\Seo\Twig;

use App\Geo\ValueObject\Canton;
use App\Search\ReadModel\LandingFooter;
use App\Search\ReadModel\LandingPage;
use App\Search\ReadModel\ListingPage;
use App\Search\ReadModel\SearchCriteria;
use App\Seo\BreadcrumbJsonLd;
use App\Seo\HomeMeta;
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
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The seam between pages and Seo. Search and Venues never depend on Seo in PHP: their templates
 * call these functions.
 *
 * - `listing_seo(criteria, page, landing = null)`, `venue_seo(venue)`: the PageMeta for
 *   `seo/_meta.html.twig`; `landing` is the LandingPage the route matched (#17).
 * - `home_seo()`: the same for the home page (#36).
 * - `site_jsonld()`, `venue_jsonld(venue)`, `breadcrumb_jsonld(items)`, `listing_jsonld(page)`:
 *   a ready script block; `listing_jsonld` is empty for a page without cards.
 * - `footer_landing_cantons()`: the live canton pages for the footer (#17), by name, from the
 *   cache item only, never a query (error pages render it).
 */
final class SeoExtension
{
    private const int JSON_LD_IMAGES = 3;

    public function __construct(
        private readonly ListingMeta $listingMeta,
        private readonly HomeMeta $homeMeta,
        private readonly VenueMeta $venueMeta,
        private readonly SiteJsonLd $siteJsonLd,
        private readonly VenueJsonLd $venueJsonLd,
        private readonly ListingJsonLd $listingJsonLd,
        private readonly UrlGeneratorInterface $urls,
        private readonly UrlHelper $urlHelper,
        private readonly LandingFooter $landingFooter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array{canton: Canton, slug: string}>
     */
    #[AsTwigFunction('footer_landing_cantons')]
    public function footerLandingCantons(): array
    {
        $cantons = $this->landingFooter->cantons();
        $collator = new \Collator($this->translator->getLocale());
        usort($cantons, fn (array $a, array $b): int => (int) $collator->compare(
            $a['canton']->trans($this->translator),
            $b['canton']->trans($this->translator),
        ));

        return $cantons;
    }

    #[AsTwigFunction('listing_seo')]
    public function listingSeo(SearchCriteria $criteria, ListingPage $page, ?LandingPage $landing = null): PageMeta
    {
        return $this->listingMeta->for($criteria, $page, $landing);
    }

    #[AsTwigFunction('home_seo')]
    public function homeSeo(): PageMeta
    {
        return $this->homeMeta->for();
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
