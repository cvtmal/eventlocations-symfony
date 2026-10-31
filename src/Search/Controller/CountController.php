<?php

declare(strict_types=1);

namespace App\Search\Controller;

use App\Search\ReadModel\KnownTermsQuery;
use App\Search\ReadModel\ListingQuery;
use App\Search\ReadModel\SearchCriteria;
use App\Shared\Http\PublicCache;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The live count of the filter overlays: `GET /_search/count?<listing query>` → `{"count": n}`.
 * Same query string and codec as the listing; invalid values are ignored. Unlocalized
 * (name and path start with `_`), noindex, no session, publicly cacheable (S10).
 */
final class CountController extends AbstractController
{
    public function __construct(
        private readonly ListingQuery $listing,
        private readonly KnownTermsQuery $knownTerms,
    ) {
    }

    #[Route('/_search/count', name: '_search_count', methods: ['GET'])]
    #[Cache(maxage: PublicCache::COUNT_BROWSER_TTL, smaxage: PublicCache::PAGE_TTL, public: true, staleWhileRevalidate: PublicCache::STALE_TTL)]
    public function __invoke(Request $request): JsonResponse
    {
        $criteria = SearchCriteria::fromQuery($request->query, $this->knownTerms->all());

        return new JsonResponse(['count' => $this->listing->count($criteria)], headers: ['X-Robots-Tag' => 'noindex']);
    }
}
