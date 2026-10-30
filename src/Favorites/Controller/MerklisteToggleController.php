<?php

declare(strict_types=1);

namespace App\Favorites\Controller;

use App\Favorites\ValueObject\MerklisteCookie;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The no-JS toggle (S7): `POST /merkliste/{number}` flips one entry and answers 303 back to the
 * page it came from. With JS, `merkliste.js` does the same in the browser.
 *
 * `intent=remove` is the "Entfernen" form: it only ever removes, so a double-click or a stale tab
 * cannot bring an entry back. The Merken button sends no intent and stays a true toggle, because a
 * cached page cannot know the state.
 *
 * The same-origin check comes first and is not optional. `SameSite=Lax` keeps the cookie off a
 * cross-site POST, but the answer's `Set-Cookie` would still be stored, so an unguarded toggle
 * lets any foreign page replace a visitor's whole list with one entry.
 *
 * No command and no handler: the list lives in the browser, nothing is persisted, and a handler
 * cannot set a response cookie. Stale numbers are not checked here; `/merkliste` prunes them.
 */
final class MerklisteToggleController extends AbstractController
{
    #[Route(path: ['de' => '/merkliste/{number}'], name: 'merkliste_toggle', requirements: ['number' => '[1-9]\d{0,9}'], methods: ['POST'])]
    public function __invoke(Request $request, int $number): Response
    {
        $token = $request->request->all()['_token'] ?? null;
        if (!\is_string($token) || !$this->isCsrfTokenValid('submit', $token)) {
            throw new AccessDeniedHttpException();
        }

        if (!MerklisteCookie::isValidNumber($number)) {
            throw new NotFoundHttpException();
        }

        $cookie = MerklisteCookie::fromRequest($request);
        if ('remove' === ($request->request->all()['intent'] ?? null)) {
            $response = self::private($this->redirect($this->target($request), Response::HTTP_SEE_OTHER));
            if ($cookie->has($number)) {
                $response->headers->setCookie($cookie->without($number)->toCookie());
            }

            return $response;
        }

        if ($cookie->isFull() && !$cookie->has($number)) {
            // The Merkliste page carries the notice; a plain return to the Referer would say nothing.
            return self::private($this->redirectToRoute('merkliste', status: Response::HTTP_SEE_OTHER));
        }

        $response = self::private($this->redirect($this->target($request), Response::HTTP_SEE_OTHER));
        $response->headers->setCookie($cookie->toggled($number)->toCookie());

        return $response;
    }

    /**
     * The trailing slash keeps `https://host.evil.example` out, so this is no open redirect.
     */
    private function target(Request $request): string
    {
        $referer = (string) $request->headers->get('Referer');

        return str_starts_with($referer, $request->getSchemeAndHttpHost().'/') ? $referer : $this->generateUrl('merkliste');
    }

    private static function private(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
