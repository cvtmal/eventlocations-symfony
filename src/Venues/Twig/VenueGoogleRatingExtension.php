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
 * - nothing while `GOOGLE_RATING_ENABLED` is off (D48, the default: Google's Maps Platform terms 3.2.3(d)(iii)
 *   restrict Places content in a directory, so the owner switches it on after checking them), without a place ID,
 *   or when staff hid the rating;
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
        #[Autowire('%env(bool:default::GOOGLE_RATING_ENABLED)%')]
        private readonly bool $enabled,
    ) {
    }

    /** D48: whether the detail page may show the rating at all; /datenschutz explains it only then. */
    #[AsTwigFunction('venue_google_rating_enabled')]
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * f16: whether the rating is live, i.e. switched on and with a `GOOGLE_MAPS_JS_KEY`, so the visitor's browser loads
     * Google's element (and `google-rating.js` has a box to fill). Switched on without the key it is a plain link.
     */
    #[AsTwigFunction('venue_google_rating_live')]
    public function live(): bool
    {
        return $this->enabled && '' !== (string) $this->mapsKey;
    }

    #[AsTwigFunction('venue_google_rating', isSafe: ['html'])]
    public function venueGoogleRating(Environment $twig, VenueDetail $venue): string
    {
        if (!$this->enabled || !$venue->showsGoogleRating() || null === $venue->googlePlaceId) {
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
