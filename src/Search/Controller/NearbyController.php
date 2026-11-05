<?php

declare(strict_types=1);

namespace App\Search\Controller;

use App\Geo\ReadModel\TownLocator;
use App\Search\NearbyCentres;
use App\Search\NearbyPins;
use App\Search\ReadModel\ListingQuery;
use App\Search\ReadModel\NearbyVenue;
use App\Search\ReadModel\NearbyVenuesQuery;
use App\Search\ReadModel\SearchCriteria;
use App\Shared\Http\PublicCache;
use App\Shared\Security\ContentSecurityPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "In der Nähe" (#52, owner request 2026-11-05): every published venue within 30 km of the
 * visitor's position or a typed PLZ/Ort, nearest first, on a Google map. Four states, each a
 * cached 200: no location (the whole-Switzerland map, no list), no location with a notice,
 * located, and nothing near (a link to the listing at 50 km).
 *
 * Without `GOOGLE_MAPS_JS_KEY` the page is the controls and the list only: no map element, no
 * pin query and no request to Google. The pins are embedded in the page, there is no endpoint.
 * The route's `_csp` default selects the maps policy and `geolocation=(self)` for it alone. The
 * key and the Map ID are in the cached HTML, so a change to either needs a purge
 * (`docs/operations.md`).
 */
final class NearbyController extends AbstractController
{
    public function __construct(
        private readonly NearbyCentres $centres,
        private readonly NearbyVenuesQuery $nearby,
        private readonly ListingQuery $listing,
        private readonly NearbyPins $pins,
        private readonly TownLocator $towns,
        #[Autowire('%env(default::GOOGLE_MAPS_JS_KEY)%')]
        private readonly ?string $mapsKey,
        #[Autowire('%env(default::GOOGLE_MAPS_MAP_ID)%')]
        private readonly ?string $mapId,
    ) {
    }

    #[Route(
        path: ['de' => '/hochzeitslocation-in-meiner-naehe'],
        name: 'nearby',
        defaults: [ContentSecurityPolicy::ROUTE_ATTRIBUTE => ContentSecurityPolicy::GOOGLE_MAPS],
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $query = $request->query->all();
        $nearbyRequest = $this->centres->resolve($query);
        $centre = $nearbyRequest->centre;
        $key = (string) $this->mapsKey;
        $locale = $request->getLocale();
        $cards = [];
        $distances = [];
        $widerHref = null;

        if (null === $centre) {
            // Keyless, nothing would use the pins.
            $venues = '' === $key ? [] : $this->nearby->all($locale);
        } else {
            $venues = $this->nearby->within($centre->point, $locale);
            $cards = $this->listing->cardsByNumbers(array_map(static fn (NearbyVenue $v): int => $v->number, $venues), $locale, 1);
            foreach ($venues as $venue) {
                \assert(null !== $venue->distanceKm);
                $distances[$venue->number] = NearbyPins::km($venue->distanceKm);
            }
            if ([] === $venues) {
                $place = $centre->listingPlace ?? $this->towns->nearest($centre->point->lat, $centre->point->lng)?->townId;
                $widerHref = null === $place
                    ? $this->generateUrl('listing')
                    : $this->generateUrl('listing', ['place' => $place, 'radius' => SearchCriteria::MAX_RADIUS]);
            }
        }

        $response = $this->render('search/nearby.html.twig', [
            'centre' => $centre,
            'notice' => $nearbyRequest->notice,
            'ort' => $nearbyRequest->ort,
            'cards' => $cards,
            'distances' => $distances,
            'venue_count' => \count($venues),
            'pins' => '' === $key ? null : $this->pins->json($venues, $locale),
            'maps_key' => $key,
            'map_id' => (string) $this->mapId,
            'radius_km' => NearbyVenuesQuery::RADIUS_KM,
            'wider_href' => $widerHref,
            'wider_km' => SearchCriteria::MAX_RADIUS,
            'filtered' => [] !== array_intersect(['lat', 'lng', 'ort'], array_keys($query)),
            'centre_point' => $centre?->point,
        ]);
        $response->setCache(PublicCache::page());

        return $response;
    }
}
