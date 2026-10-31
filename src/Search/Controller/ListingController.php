<?php

declare(strict_types=1);

namespace App\Search\Controller;

use App\Search\ReadModel\KnownTermsQuery;
use App\Search\ReadModel\LandingPage;
use App\Search\ReadModel\LandingPages;
use App\Search\ReadModel\ListingQuery;
use App\Search\ReadModel\SearchCriteria;
use App\Shared\Http\PublicCache;
use App\Shared\ValueObject\PageLinks;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public listing `/hochzeitslocations` (LP-1…LP-6) and its landing pages (#17):
 * `/hochzeitslocations/{slug}` (a canton, else a building type) and
 * `/hochzeitslocations/{canton}/{town}`. A landing path stands for its facets, which win over
 * the same parameters in the query string; every other filter stays in the query (#6 codec).
 * An unknown or under-threshold landing page and a page beyond the last answer 404, never a
 * redirect (S5); invalid values are ignored. No session, publicly cacheable (S10): a page until
 * the next Zurich midnight (the day's order seed), the thrown 404 for an hour (the attribute);
 * no X-Robots-Tag: the template's robots meta marks filter states `noindex, follow` (#16).
 */
final class ListingController extends AbstractController
{
    public function __construct(
        private readonly ListingQuery $listing,
        private readonly KnownTermsQuery $knownTerms,
        private readonly LandingPages $landings,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(path: ['de' => '/hochzeitslocations'], name: 'listing', methods: ['GET'])]
    #[Cache(public: true, smaxage: PublicCache::MISSING_TTL)]
    public function __invoke(Request $request): Response
    {
        return $this->page($request, null);
    }

    #[Route(path: ['de' => '/hochzeitslocations/{slug}'], name: 'listing_landing', requirements: ['slug' => LandingPage::SLUG_REQUIREMENT], methods: ['GET'])]
    #[Cache(public: true, smaxage: PublicCache::MISSING_TTL)]
    public function landing(Request $request, string $slug): Response
    {
        return $this->page($request, $this->landings->bySlug($slug) ?? throw $this->createNotFoundException());
    }

    #[Route(path: ['de' => '/hochzeitslocations/{canton}/{town}'], name: 'listing_town', requirements: ['canton' => LandingPage::SLUG_REQUIREMENT, 'town' => LandingPage::SLUG_REQUIREMENT], methods: ['GET'])]
    #[Cache(public: true, smaxage: PublicCache::MISSING_TTL)]
    public function town(Request $request, string $canton, string $town): Response
    {
        return $this->page($request, $this->landings->byTown($canton, $town) ?? throw $this->createNotFoundException());
    }

    private function page(Request $request, ?LandingPage $landing): Response
    {
        // The path's facets win over the same keys in the query; fromQuery() type-checks every value.
        /** @var InputBag<string> $query */
        $query = new InputBag(($landing?->facetQuery() ?? []) + $request->query->all());
        $criteria = SearchCriteria::fromQuery($query, $this->knownTerms->all());
        // Before the query, which takes its day seed then: a render crossing midnight must not
        // store yesterday's order with a full day's lifetime.
        $now = $this->clock->now();
        $page = $this->listing->results($criteria, $request->getLocale());
        if ($criteria->page > $page->lastPage) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('search/listing.html.twig', [
            'criteria' => $criteria,
            'page' => $page,
            'page_links' => PageLinks::around($page->page, $page->lastPage),
            'landing' => $landing,
        ]);

        return $response->setCache(PublicCache::listing($now));
    }
}
