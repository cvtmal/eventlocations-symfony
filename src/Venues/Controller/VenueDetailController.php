<?php

declare(strict_types=1);

namespace App\Venues\Controller;

use App\Venues\ReadModel\VenueDetailQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The venue detail page (#8, DP-1 to DP-16): current slug 200, a `venue_slug_history` slug 301
 * to the current one (S5), an unpublished venue 410, anything else 404. No session, publicly
 * cacheable (S10). "Ähnliche Locations" is a Search fragment embedded by the template.
 */
final class VenueDetailController extends AbstractController
{
    public const SLUG_REQUIREMENT = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public function __construct(
        private readonly VenueDetailQuery $venues,
    ) {
    }

    #[Route(path: ['de' => '/hochzeitslocation/{slug}'], name: 'venue_show', requirements: ['slug' => self::SLUG_REQUIREMENT], methods: ['GET'])]
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

        return self::cached($this->render('venues/detail/show.html.twig', ['venue' => $detail]));
    }

    /**
     * S10, set here rather than with #[Cache] so the 301 gets the same headers as the 200.
     */
    private static function cached(Response $response): Response
    {
        $response->setCache(['public' => true, 's_maxage' => 600, 'stale_while_revalidate' => 86400]);

        return $response;
    }
}
