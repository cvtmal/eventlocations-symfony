<?php

declare(strict_types=1);

namespace App\Venues\Twig;

use App\Shared\Security\ContentSecurityPolicy;
use App\Venues\ReadModel\VenueDetail;
use App\Venues\ValueObject\GooglePlaceId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;
use Twig\Environment;

/**
 * `{{ venue_google_rating(venue) }}` fills the detail page's `{% block google_rating %}` (#53, D1, D9):
 *
 * - nothing without a place ID or when staff hid the rating;
 * - with an empty or unset `GOOGLE_MAPS_JS_KEY`, only the link to the venue's Google reviews;
 * - with a key, also the box `google-rating.js` fills with Google's compact Place Details element.
 *
 * It stores and renders no Google value (Maps Platform Terms §3.2.3): the cached page carries only the place ID
 * and the referrer-restricted key, and Google's element fetches the rating in the visitor's browser. A key change
 * therefore needs a purge. The CSP is set here, not as a route default, because only here do we know that Google's
 * element renders; that covers the inquiry form's 422 re-render too, and the previews override the block.
 */
final class VenueGoogleRatingExtension
{
    public function __construct(
        #[Autowire('%env(default::GOOGLE_MAPS_JS_KEY)%')]
        private readonly ?string $mapsKey,
        private readonly RequestStack $requests,
    ) {
    }

    #[AsTwigFunction('venue_google_rating', isSafe: ['html'])]
    public function venueGoogleRating(Environment $twig, VenueDetail $venue): string
    {
        if (!$venue->showsGoogleRating() || null === $venue->googlePlaceId) {
            return '';
        }

        $key = (string) $this->mapsKey;
        if ('' !== $key) {
            $this->requests->getMainRequest()?->attributes->set(ContentSecurityPolicy::ROUTE_ATTRIBUTE, ContentSecurityPolicy::GOOGLE_PLACES);
        }

        return $twig->render('venues/detail/_google_rating.html.twig', [
            'place_id' => $venue->googlePlaceId,
            'maps_key' => '' === $key ? null : $key,
            'maps_url' => GooglePlaceId::mapsUrl($venue->name, $venue->googlePlaceId),
        ]);
    }
}
