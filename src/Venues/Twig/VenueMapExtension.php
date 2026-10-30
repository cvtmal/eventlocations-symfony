<?php

declare(strict_types=1);

namespace App\Venues\Twig;

use App\Venues\ReadModel\VenueDetail;
use App\Venues\ValueObject\MapLocation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Attribute\AsTwigFunction;
use Twig\Environment;

/**
 * `{{ venue_map(venue) }}` fills #8's `{% block map %}` (#15, DP-10). It adds no query and no
 * session, and renders the same for every visitor, so the detail page stays cacheable (S10):
 *
 * - nothing without both coordinates, so no pin is ever guessed from a town or canton;
 * - nothing for town-centre coordinates (#24 'Ortsmitte übernehmen'): they are not the venue's position;
 * - with an empty or unset `GOOGLE_MAPS_EMBED_KEY`, the facade with the Google Maps link only (Q3);
 * - with a key, also "Karte laden" and the embed iframe, inert in a `<template>` until the click.
 */
final class VenueMapExtension
{
    public function __construct(
        #[Autowire('%env(default::GOOGLE_MAPS_EMBED_KEY)%')]
        private readonly ?string $embedKey,
        private readonly LocaleSwitcher $locale,
    ) {
    }

    #[AsTwigFunction('venue_map', isSafe: ['html'])]
    public function venueMap(Environment $twig, VenueDetail $venue): string
    {
        $location = $this->location($venue);
        if (null === $location) {
            return '';
        }

        $key = (string) $this->embedKey;

        return $twig->render('venues/detail/_map.html.twig', [
            'venue' => $venue,
            'search_url' => $location->searchUrl(),
            'embed_url' => '' === $key ? null : $location->embedUrl($key, $this->locale->getLocale()),
        ]);
    }

    /** The one rule for "has a map". */
    private function location(VenueDetail $venue): ?MapLocation
    {
        if (null === $venue->lat || null === $venue->lng || $venue->coordinatesFromTown) {
            return null;
        }

        return new MapLocation($venue->lat, $venue->lng);
    }
}
