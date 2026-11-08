<?php

declare(strict_types=1);

namespace App\Venues\Controller;

use App\Shared\Http\PublicCache;
use App\Venues\ReadModel\VenueDetailQuery;
use App\Venues\VenueClaims;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The venue detail page (#8, DP-1 to DP-16): current slug 200, a `venue_slug_history` slug 301
 * to the current one (S5), an unpublished venue 410, anything else 404. No session, publicly
 * cacheable (S10): a day for the 200 and 301, an hour for the thrown 404 and 410. "Ähnliche Locations" is a Search fragment embedded by the template.
 * A page with a «Kurzfristig frei» section (G14) expires at Zurich midnight instead, like a listing: an offer's last
 * day ends there, so a missed nightly purge cannot keep a past offer on the page for another day.
 */
final class VenueDetailController extends AbstractController
{
    public const SLUG_REQUIREMENT = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public function __construct(
        private readonly VenueDetailQuery $venues,
        private readonly VenueClaims $claims,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(path: ['de' => '/eventlokal/{slug}'], name: 'venue_show', requirements: ['slug' => self::SLUG_REQUIREMENT], methods: ['GET'])]
    #[Cache(public: true, smaxage: PublicCache::MISSING_TTL)]
    public function __invoke(Request $request, string $slug): Response
    {
        $locale = $request->getLocale();
        $match = $this->venues->resolveSlug($slug, $locale);
        if (null === $match) {
            throw new NotFoundHttpException();
        }
        if (!$match->isPublished) {
            throw new GoneHttpException();
        }
        if (!$match->isCurrent) {
            return self::cached($this->redirectToRoute('venue_show', ['slug' => $match->currentSlug], Response::HTTP_MOVED_PERMANENTLY));
        }

        $detail = $this->venues->detail($match->number, $locale) ?? throw new NotFoundHttpException();

        return self::cached($this->render('venues/detail/show.html.twig', [
            'venue' => $detail,
            // D18: until the operator claims the entry (G6). A claim purges the cached page.
            'unconfirmed' => !$this->claims->isConfirmed($detail->id),
        ]), $detail->hasLastMinuteOffers() ? PublicCache::listing($this->clock->now()) : PublicCache::page());
    }

    /**
     * S10: the 200 and the 301 set every directive here, so the attribute, which never
     * overwrites a directive the response already has, covers only the thrown 404 and 410.
     *
     * @param array{public: true, s_maxage: int, stale_while_revalidate: int, stale_if_error: int} $cache
     */
    private static function cached(Response $response, ?array $cache = null): Response
    {
        $response->setCache($cache ?? PublicCache::page());

        return $response;
    }
}
