<?php

declare(strict_types=1);

namespace App\Venues\Controller;

use App\Shared\Security\ClientIpKey;
use App\Venues\ReadModel\VenueDetailQuery;
use App\Venues\ValueObject\PhoneNumber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The phone reveal of the detail page (DP-11): `GET /hochzeitslocation/{slug}/telefon`. A plain
 * link without JS (an HTML page with the `tel:` link), JSON `{"phone", "display"}` for
 * `phone-reveal.js` (`Accept: application/json`). Rate-limited per client IP (`phone_reveal`,
 * config/packages/rate_limiter.yaml) before anything else, so it also blocks slug enumeration.
 * Never cached, never indexed.
 */
final class VenuePhoneController extends AbstractController
{
    private const HEADERS = ['X-Robots-Tag' => 'noindex, nofollow', 'Vary' => 'Accept'];

    public function __construct(
        private readonly VenueDetailQuery $venues,
        private readonly RateLimiterFactoryInterface $phoneRevealLimiter,
        private readonly ClientIpKey $clientIpKey,
    ) {
    }

    #[Route(path: ['de' => '/hochzeitslocation/{slug}/telefon'], name: 'venue_phone', requirements: ['slug' => VenueDetailController::SLUG_REQUIREMENT], methods: ['GET'])]
    public function __invoke(Request $request, string $slug): Response
    {
        $limit = $this->phoneRevealLimiter->create($this->clientIpKey->of($request))->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            throw new TooManyRequestsHttpException($retryAfter, headers: self::HEADERS + ['Cache-Control' => 'private, no-store']);
        }

        $match = $this->venues->resolveSlug($slug, $request->getLocale());
        if (null === $match) {
            throw new NotFoundHttpException();
        }
        if (!$match->isPublished) {
            throw new GoneHttpException();
        }
        if (!$match->isCurrent) {
            return self::private($this->redirectToRoute('venue_phone', ['slug' => $match->currentSlug], Response::HTTP_MOVED_PERMANENTLY));
        }

        $e164 = $this->venues->phone($match->venueId) ?? throw new NotFoundHttpException();
        $phone = PhoneNumber::fromE164($e164);

        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return self::private(new JsonResponse(['phone' => $phone->e164(), 'display' => $phone->display()]));
        }

        return self::private($this->render('venues/phone.html.twig', [
            'name' => $match->name,
            'slug' => $match->currentSlug,
            'e164' => $phone->e164(),
            'display' => $phone->display(),
        ]));
    }

    private static function private(Response $response): Response
    {
        $response->headers->add(self::HEADERS);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
