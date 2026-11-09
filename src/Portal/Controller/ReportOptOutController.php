<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Portal\Command\OptOutOfOwnerReports;
use App\Portal\Command\ResumeOwnerReports;
use App\Portal\ReadModel\OwnerReportQuery;
use App\Portal\ReadModel\ReportOptOutState;
use App\Portal\Security\ReportOptOutToken;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The monthly report's opt-out link (G10): whoever holds it may turn that one venue's report off and on again, with
 * no login. A GET only shows the state, so a mail gateway that fetches the link changes nothing. Any POST turns the
 * report off (RFC 8058 §3.2: the mail client's one-click POST carries `List-Unsubscribe=One-Click` and no cookie),
 * except `resubscribe=1`, which turns it on again. The answer is 200 in place: no redirect, no CSRF token and no
 * session, because the one-click POST has neither cookie nor form (RFC 8058 §3.1). Both writes are idempotent.
 *
 * A dead link (bad signature, venue gone) answers 410 in place, like the cancellation link of G9.
 */
final class ReportOptOutController extends AbstractController
{
    public function __construct(
        private readonly ReportOptOutToken $tokens,
        private readonly OwnerReportQuery $reports,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/konto/bericht-abbestellen/{token}', name: 'portal_report_opt_out', requirements: ['token' => ReportOptOutToken::REQUIREMENT], methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, string $token): Response
    {
        $venueId = $this->tokens->venueIdFrom($token);
        $state = null === $venueId ? null : $this->reports->optOutState($venueId);
        if (null === $venueId || null === $state) {
            return $this->render('portal/security/notice.html.twig', [
                'title' => 'report.opt_out_page.invalid.title',
                'text' => 'report.opt_out_page.invalid.text',
                'link' => 'report.opt_out_page.invalid.link',
                'link_route' => 'portal_login',
            ], new Response(status: Response::HTTP_GONE));
        }

        $resumed = false;
        if ($request->isMethod('POST')) {
            $resumed = '1' === $request->request->get('resubscribe');
            $this->commandBus->dispatch($resumed ? new ResumeOwnerReports($venueId) : new OptOutOfOwnerReports($venueId));
            $state = new ReportOptOutState($state->venueName, !$resumed);
        }

        return $this->render('portal/report/opt_out.html.twig', ['state' => $state, 'resumed' => $resumed]);
    }
}
