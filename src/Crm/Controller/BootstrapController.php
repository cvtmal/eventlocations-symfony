<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\CompanyBootstrap;
use App\Crm\ValueObject\CrmFlash;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The company list's bootstrap button (D19): a batch per POST, because a whole run does not fit a
 * request's `max_execution_time`. The flash says how many venues remain, and the list offers the
 * button until none do.
 */
#[IsGranted('ROLE_EDITOR')]
final class BootstrapController extends AbstractController
{
    public const int BATCH = 100;
    public const string TOKEN = 'submit';

    #[Route('/admin/crm/firmen/bootstrap', name: 'admin_crm_companies_bootstrap', methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, CompanyBootstrap $bootstrap): Response
    {
        $token = $request->request->all()['_token'] ?? null;
        if (!\is_string($token) || !$this->isCsrfTokenValid(self::TOKEN, $token)) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));

            return $this->redirectToRoute('admin_crm_companies', status: Response::HTTP_SEE_OTHER);
        }

        $report = $bootstrap->run(self::BATCH);
        $this->addFlash('success', CrmFlash::message('bootstrap.flash.done', [
            'companies' => $report->companies,
            'contacts' => $report->contacts,
            'without' => $report->withoutContactPerson,
            'taken' => $report->emailsTaken,
            'shared' => $report->sharedEmails,
            'skipped' => $report->skipped,
            'failed' => $report->failed(),
            'remaining' => $report->remaining,
        ]));
        if ([] !== $report->failures) {
            $this->addFlash('error', CrmFlash::message('bootstrap.flash.failures', ['failures' => implode(', ', $report->failures)]));
        }

        return $this->redirectToRoute('admin_crm_companies', status: Response::HTTP_SEE_OTHER);
    }
}
