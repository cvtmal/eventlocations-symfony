<?php

declare(strict_types=1);

namespace App\Search\Controller;

use App\Search\ReadModel\SimilarVenuesQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Ähnliche Locations" of the venue detail page (DP-15), as an embedded fragment: the Venues
 * template `venues/detail/show.html.twig` renders it with
 * `render(controller('App\\Search\\Controller\\SimilarVenuesController', {venueId: venue.id}))`,
 * so no PHP class in Venues depends on Search (S2). It has no route on purpose: it is never
 * requested on its own. An empty result renders nothing (no heading). The template builds the
 * photos with #7's `card_picture()`.
 */
final class SimilarVenuesController extends AbstractController
{
    public function __construct(
        private readonly SimilarVenuesQuery $similarVenues,
    ) {
    }

    public function __invoke(Request $request, string $venueId): Response
    {
        $venues = $this->similarVenues->forVenue($venueId, $request->getLocale());
        if ([] === $venues) {
            return new Response('');
        }

        return $this->render('search/_similar_venues.html.twig', ['venues' => $venues]);
    }
}
