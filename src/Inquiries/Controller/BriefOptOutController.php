<?php

declare(strict_types=1);

namespace App\Inquiries\Controller;

use App\Inquiries\BriefOptOutToken;
use App\Inquiries\ReadModel\BriefOptOutVenue;
use App\Inquiries\ReadModel\BriefQuery;
use App\Venues\Command\ChangeMultiInquiryConsent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `/sammelanfrage/abbestellen/{token}` (F13): the venue's opt-out of briefs from the link in such a mail. GET asks,
 * POST switches briefs off (a second POST changes nothing); single inquiries reach the venue either way, and the
 * contact card in `/konto` and the CMS switches briefs on again. No CSRF token: the only thing a forged POST can do
 * is what the link's holder may do anyway. Private like the feedback opt-out: `no-store`, `noindex`, no beacon, no
 * session, `Referrer-Policy: no-referrer`; an unknown token or venue is 404, other methods 405.
 */
final class BriefOptOutController extends AbstractController
{
    public function __construct(
        private readonly BriefOptOutToken $tokens,
        private readonly BriefQuery $briefs,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route(path: ['de' => '/sammelanfrage/abbestellen/{token}'], name: 'inquiry_brief_opt_out', requirements: ['token' => '[^/]+'])]
    public function __invoke(Request $request, string $token): Response
    {
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD') && !$request->isMethod('POST')) {
            $response = $this->page('unknown', Response::HTTP_METHOD_NOT_ALLOWED, $token);
            $response->headers->set('Allow', 'GET, HEAD, POST');

            return $response;
        }
        $number = $this->tokens->venueNumberFrom($token);
        $venue = null === $number ? null : $this->briefs->optOutVenue($number);
        if (null === $venue) {
            return $this->page('unknown', Response::HTTP_NOT_FOUND, $token);
        }

        if ($request->isMethod('POST')) {
            if ($venue->acceptsMultiInquiries) {
                $this->commandBus->dispatch(new ChangeMultiInquiryConsent($venue->number, false));
            }

            return $this->page('done', Response::HTTP_OK, $token, $venue);
        }

        return $this->page($venue->acceptsMultiInquiries ? 'ask' : 'done', Response::HTTP_OK, $token, $venue);
    }

    private function page(string $state, int $status, string $token, ?BriefOptOutVenue $venue = null): Response
    {
        $response = $this->render('inquiries/brief_opt_out.html.twig', [
            'state' => $state,
            'token' => $token,
            'venue' => $venue,
        ], new Response(status: $status));
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
