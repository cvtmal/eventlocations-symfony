<?php

declare(strict_types=1);

namespace App\Seo\Controller;

use App\Seo\MarkdownText;
use App\Shared\Http\PublicCache;
use App\Venues\Controller\VenueDetailController;
use App\Venues\ReadModel\VenueDetailQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The Markdown view of a venue for language models, linked from `/llms.txt` and the detail
 * page's `<link rel="alternate">`. It answers exactly as `venue_show` does (200, 301 from a
 * history slug, 410 unpublished, 404), with the same cache lifetimes. `noindex` and a canonical
 * `Link` header keep it out of search results as a duplicate of the HTML page.
 */
final class VenueMarkdownController extends AbstractController
{
    public function __construct(
        private readonly VenueDetailQuery $venues,
    ) {
    }

    #[Route(path: ['de' => '/hochzeitslocation/{slug}.md'], name: 'venue_markdown', requirements: ['slug' => VenueDetailController::SLUG_REQUIREMENT], methods: ['GET'])]
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
            return self::cached($this->redirectToRoute('venue_markdown', ['slug' => $match->currentSlug], Response::HTTP_MOVED_PERMANENTLY));
        }

        $detail = $this->venues->detail($match->number, $locale) ?? throw new NotFoundHttpException();
        $html = $this->generateUrl('venue_show', ['slug' => $detail->slug], UrlGeneratorInterface::ABSOLUTE_URL);

        return self::cached(new Response(
            MarkdownText::tidy($this->renderView('seo/venue.md.twig', ['venue' => $detail])),
            headers: [
                'Content-Type' => 'text/markdown; charset=UTF-8',
                'X-Robots-Tag' => 'noindex',
                'Link' => \sprintf('<%s>; rel="canonical"', $html),
            ],
        ));
    }

    /**
     * The 200 and the 301 set every directive here; the attribute, which never overwrites a
     * directive the response already has, covers only the thrown 404 and 410.
     */
    private static function cached(Response $response): Response
    {
        $response->setCache(PublicCache::page());

        return $response;
    }
}
