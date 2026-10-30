<?php

declare(strict_types=1);

namespace App\Search\Controller;

use App\Search\ReadModel\CantonCount;
use App\Search\ReadModel\KnownTermsQuery;
use App\Search\ReadModel\ListingQuery;
use App\Search\ReadModel\SearchCriteria;
use App\Search\ReadModel\TopCantonsQuery;
use App\Search\ValueObject\PageLinks;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public listing `/hochzeitslocations` (LP-1…LP-6): the filter state from the query
 * string (#6 codec), one page of cards, and "Andere suchten auch nach". A page beyond the
 * last answers 404; invalid values are ignored. No session, publicly cacheable (S10); no
 * X-Robots-Tag (#16 decides noindex for filter combinations).
 */
final class ListingController extends AbstractController
{
    public function __construct(
        private readonly ListingQuery $listing,
        private readonly KnownTermsQuery $knownTerms,
        private readonly TopCantonsQuery $topCantons,
    ) {
    }

    #[Route(path: ['de' => '/hochzeitslocations'], name: 'listing', methods: ['GET'])]
    #[Cache(public: true, smaxage: 600, staleWhileRevalidate: 86400)]
    public function __invoke(Request $request): Response
    {
        $criteria = SearchCriteria::fromQuery($request->query, $this->knownTerms->all());
        $page = $this->listing->results($criteria, $request->getLocale());
        if ($criteria->page > $page->lastPage) {
            throw $this->createNotFoundException();
        }

        return $this->render('search/listing.html.twig', [
            'criteria' => $criteria,
            'page' => $page,
            'page_links' => PageLinks::around($page->page, $page->lastPage),
            'related_cantons' => array_map(fn (CantonCount $top): array => [
                'canton' => $top->canton,
                'href' => $this->generateUrl('listing', SearchCriteria::none()->withCanton($top->canton)->toQuery()),
            ], $this->topCantons->top()),
        ]);
    }
}
