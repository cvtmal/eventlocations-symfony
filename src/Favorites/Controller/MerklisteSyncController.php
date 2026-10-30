<?php

declare(strict_types=1);

namespace App\Favorites\Controller;

use App\Favorites\ValueObject\MerklisteCookie;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /merkliste`: the server re-issues a list that `merkliste.js` wrote, because WebKit caps a
 * cookie written by `document.cookie` at 7 days and leaves one set by a response alone (MK-2).
 *
 * A route of its own, not an intent on `POST /merkliste/{number}`: it has no number, so nothing can
 * flip, and a lost field cannot fall through to a toggle. It sends the request's own list back and
 * never deletes one. 204 and no `Location`, because `HttpCache` invalidates the `Location` of a
 * successful POST, and that would be the cached page the visitor is on.
 *
 * The same-origin check is the toggle's: this endpoint must not answer foreign origins at all.
 */
final class MerklisteSyncController extends AbstractController
{
    #[Route(path: ['de' => '/merkliste'], name: 'merkliste_sync', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $token = $request->request->all()['_token'] ?? null;
        if (!\is_string($token) || !$this->isCsrfTokenValid('submit', $token)) {
            throw new AccessDeniedHttpException();
        }

        $cookie = MerklisteCookie::fromRequest($request);
        $received = $request->cookies->all()[MerklisteCookie::NAME] ?? null;

        $response = new Response(status: Response::HTTP_NO_CONTENT);
        if ([] !== $cookie->numbers() && $cookie->value() !== $received) {
            $response->headers->setCookie($cookie->toCookie());
        }

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
