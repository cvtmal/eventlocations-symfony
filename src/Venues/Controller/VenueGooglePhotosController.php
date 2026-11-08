<?php

declare(strict_types=1);

namespace App\Venues\Controller;

use App\Venues\GooglePlacesPhotos;
use App\Venues\ReadModel\VenueDetail;
use App\Venues\ReadModel\VenueDetailQuery;
use App\Venues\ValueObject\GooglePlacePhoto;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Google Places photos of a venue without public photos of its own (D12, places-photos), passed through our
 * server so the visitor's browser never contacts Google:
 *
 * - `venue_google_photos` `GET /eventlokal/{slug}/google-fotos`: the strip as an HTML fragment for
 *   `google-photos.js`, or 204 when there is nothing to show (no key, over the daily cap, Google answered nothing);
 *   the page then keeps F6's placeholder.
 * - `venue_google_photo` `GET /eventlokal/{slug}/google-fotos/{reference}`: one photo's bytes, or 204 like the
 *   fragment (the image's error event then removes it from the strip). The photo name is rebuilt from the venue's
 *   own place ID, so the route passes on photos of that place only.
 *
 * Both answer only for a published venue under its current slug that shows Google's photos
 * (`VenueDetail::showsGooglePhotos()`), never for a preview, and always `private, no-store` and `noindex`, with no
 * session, no cookie and no stats beacon: the cached detail page carries only the fragment's URL, never a Google
 * value (D12: never stored or cached). A 204 is a bare answer, never the cacheable error page.
 */
final class VenueGooglePhotosController extends AbstractController
{
    private const array HEADERS = ['X-Robots-Tag' => 'noindex, nofollow', 'X-Content-Type-Options' => 'nosniff'];

    public function __construct(
        private readonly VenueDetailQuery $venues,
        private readonly GooglePlacesPhotos $google,
    ) {
    }

    #[Route(path: ['de' => '/eventlokal/{slug}/google-fotos'], name: 'venue_google_photos', requirements: ['slug' => VenueDetailController::SLUG_REQUIREMENT], methods: ['GET'])]
    public function photos(Request $request, string $slug): Response
    {
        $venue = $this->venue($slug, $request->getLocale());
        $photos = null === $venue || null === $venue->googlePlaceId ? [] : $this->google->photos($venue->googlePlaceId);
        if (null === $venue || [] === $photos) {
            return self::private(new Response(null, Response::HTTP_NO_CONTENT));
        }

        return self::private($this->render('venues/detail/google_photos.html.twig', [
            'name' => $venue->name,
            'slug' => $venue->slug,
            'photos' => $photos,
        ]));
    }

    #[Route(
        path: ['de' => '/eventlokal/{slug}/google-fotos/{reference}'],
        name: 'venue_google_photo',
        requirements: ['slug' => VenueDetailController::SLUG_REQUIREMENT, 'reference' => GooglePlacePhoto::REFERENCE_REQUIREMENT],
        methods: ['GET'],
    )]
    public function photo(Request $request, string $slug, string $reference): Response
    {
        $venue = $this->venue($slug, $request->getLocale());
        $image = null === $venue || null === $venue->googlePlaceId ? null : $this->google->image($venue->googlePlaceId, $reference);
        if (null === $image) {
            return self::private(new Response(null, Response::HTTP_NO_CONTENT));
        }

        return self::private(new Response($image->bytes, Response::HTTP_OK, ['Content-Type' => $image->contentType]));
    }

    /**
     * The published venue under its current slug, when its page shows Google's photos; null otherwise.
     */
    private function venue(string $slug, string $locale): ?VenueDetail
    {
        if (!$this->google->enabled()) {
            return null;
        }
        $match = $this->venues->resolveSlug($slug, $locale);
        if (null === $match || !$match->isPublished || !$match->isCurrent) {
            return null;
        }
        $venue = $this->venues->detail($match->number, $locale);

        return null !== $venue && $venue->showsGooglePhotos() ? $venue : null;
    }

    private static function private(Response $response): Response
    {
        $response->headers->add(self::HEADERS);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
