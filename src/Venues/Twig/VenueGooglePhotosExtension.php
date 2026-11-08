<?php

declare(strict_types=1);

namespace App\Venues\Twig;

use App\Venues\GooglePlacesPhotos;
use App\Venues\ReadModel\VenueDetail;
use Twig\Attribute\AsTwigFunction;
use Twig\Environment;

/**
 * `{{ venue_google_photos(venue) }}` fills the detail page's `{% block google_photos %}` beside F6's placeholder
 * (D12, places-photos): an empty box with the URL of our fragment, which `google-photos.js` fetches after the page
 * has loaded. Nothing without `GOOGLE_PLACES_PHOTOS_KEY`, without a place ID, or once the venue has a public photo
 * of its own. The cached page holds no Google value and the visitor's browser never contacts Google, so the page
 * needs no CSP of Google's. The previews override the block: no Google call behind a login or a preview token.
 */
final class VenueGooglePhotosExtension
{
    public function __construct(
        private readonly GooglePlacesPhotos $google,
    ) {
    }

    #[AsTwigFunction('venue_google_photos', isSafe: ['html'])]
    public function venueGooglePhotos(Environment $twig, VenueDetail $venue): string
    {
        if (!$this->google->enabled() || !$venue->showsGooglePhotos()) {
            return '';
        }

        return $twig->render('venues/detail/_google_photos.html.twig', ['slug' => $venue->slug]);
    }
}
