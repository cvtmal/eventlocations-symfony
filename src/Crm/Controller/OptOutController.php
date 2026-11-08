<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Command\SuppressCompany;
use App\Crm\OptOutToken;
use App\Crm\ReadModel\CompanyRecordQuery;
use App\Crm\ReadModel\SuppressionQuery;
use App\Crm\ValueObject\SuppressionReason;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The opt-out link of every CRM mail (G11): `GET /abmelden/{token}` asks, `POST` puts the Location and its addresses on
 * the suppression list for good. A GET never writes, because mail scanners open links; the POST is also RFC 8058's
 * one-click target (`List-Unsubscribe-Post`). The signed token is the only proof, so the form has no CSRF token, and
 * no session or cookie starts (public pages start none). A second POST changes nothing and says the same.
 *
 * Private like the preview: `no-store`, `noindex`, no referrer, no stats beacon, no canonical. Any method and any
 * segment land here, so a near miss gets this page and never the cacheable error page.
 */
final class OptOutController extends AbstractController
{
    private const array HEADERS = ['X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(
        private readonly OptOutToken $tokens,
        private readonly CompanyRecordQuery $companies,
        private readonly SuppressionQuery $suppressions,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route(path: ['de' => '/abmelden/{token}'], name: 'crm_opt_out', requirements: ['token' => '[^/]+'])]
    public function __invoke(Request $request, string $token): Response
    {
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD') && !$request->isMethod('POST')) {
            $response = $this->page('unknown', Response::HTTP_METHOD_NOT_ALLOWED, $token);
            $response->headers->set('Allow', 'GET, HEAD, POST');

            return $response;
        }

        $companyId = $this->tokens->companyIdFrom($token);
        if (null === $companyId || null === $this->companies->name($companyId)) {
            return $this->page('unknown', Response::HTTP_NOT_FOUND, $token);
        }

        $suppressed = $this->suppressions->isLocationSuppressed($companyId);
        if ($request->isMethod('POST')) {
            if (!$suppressed) {
                $this->commandBus->dispatch(new SuppressCompany($companyId, SuppressionReason::OptOut));
            }

            return $this->page('done', Response::HTTP_OK, $token);
        }

        return $this->page($suppressed ? 'done' : 'ask', Response::HTTP_OK, $token);
    }

    private function page(string $state, int $status, string $token): Response
    {
        $response = $this->render('crm/opt_out.html.twig', ['state' => $state, 'token' => $token], new Response(status: $status));
        $response->headers->add(self::HEADERS);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
