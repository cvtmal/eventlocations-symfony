<?php

declare(strict_types=1);

namespace App\Venues\Controller;

use App\Shared\Command\RecordFunnelEvent;
use App\Shared\Funnel\FunnelEvent;
use App\Venues\PreviewToken;
use App\Venues\ReadModel\VenueDetailQuery;
use App\Venues\ReadModel\VenuePreviewLink;
use App\Venues\ReadModel\VenuePreviewQuery;
use App\Venues\ValueObject\PreviewShortCode;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The private preview of an entry (G5): whoever holds a link staff issued sees the entry as it would
 * look, without an account, with the banner "Noch nicht vom Betreiber bestätigt" and the photos the
 * public may see (`VenueDetailQuery::preview()`). A wrong token is a 404, an expired or revoked link a
 * 410.
 *
 * Every answer is private (`no-store`), `noindex` and sent with `Referrer-Policy: no-referrer`, so the
 * token reaches no shared cache, no search engine and no Referer of the page's own asset requests.
 * The 404 and 410 are rendered here, not thrown: nothing goes through the error logger. The token is
 * never logged (nginx skips `/vorschau/` and `/v/` in its access log, Sentry's event URL is scrubbed by
 * `Shared\Http\SensitiveUrlScrubber`). A GET that shows the entry records `preview_opened` with the
 * link's campaign (G8; none for a link sent by e-mail); a HEAD records nothing.
 *
 * Both routes take any segment and any method: a near miss (a token with a full stop from a plain-text mail, a POST)
 * must get this private page too, not the generic error page, which is cacheable and sends the stats beacon with the
 * path.
 */
final class VenuePreviewLinkController extends AbstractController
{
    private const array HEADERS = ['X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(
        private readonly PreviewToken $tokens,
        private readonly VenuePreviewQuery $previews,
        private readonly VenueDetailQuery $venues,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Any segment and any method: a near miss (a token with a full stop from a plain-text mail, a POST)
     * must get this private page too, not the generic error page, which is cacheable and sends the stats
     * beacon with the path.
     */
    #[Route(path: ['de' => '/vorschau/{token}'], name: 'venue_preview', requirements: ['token' => '[^/]+'])]
    public function __invoke(Request $request, string $token): Response
    {
        $id = $this->tokens->idFrom($token);

        return $this->show($request, null === $id ? null : $this->previews->find($id));
    }

    /**
     * The short form a letter prints (G11): `/v/<code>` shows the same private page as the token, without a redirect,
     * so the token never travels. Case and surrounding blanks of a typed code do not matter.
     */
    #[Route(path: ['de' => '/v/{code}'], name: 'venue_preview_short', requirements: ['code' => '[^/]+'])]
    public function short(Request $request, string $code): Response
    {
        $code = PreviewShortCode::normalise($code);

        return $this->show($request, null === $code ? null : $this->previews->findByShortCode($code));
    }

    private function show(Request $request, ?VenuePreviewLink $link): Response
    {
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            $response = $this->unavailable(Response::HTTP_METHOD_NOT_ALLOWED);
            $response->headers->set('Allow', 'GET, HEAD');

            return $response;
        }

        if (null === $link) {
            return $this->unavailable(Response::HTTP_NOT_FOUND);
        }
        if (!$link->isOpen($this->clock->now())) {
            return $this->unavailable(Response::HTTP_GONE);
        }

        $venue = $this->venues->preview($link->venueNumber);
        if (null === $venue) {
            return $this->unavailable(Response::HTTP_NOT_FOUND);
        }

        if ($request->isMethod('GET')) {
            $this->commandBus->dispatch(new RecordFunnelEvent(FunnelEvent::PreviewOpened, $link->venueId, $link->campaignCode));
        }

        return self::private($this->render('venues/preview/show.html.twig', ['venue' => $venue, 'token' => $token]));
    }

    private function unavailable(int $status): Response
    {
        return self::private($this->render('venues/preview/unavailable.html.twig', ['gone' => Response::HTTP_GONE === $status], new Response(status: $status)));
    }

    private static function private(Response $response): Response
    {
        $response->headers->add(self::HEADERS);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
