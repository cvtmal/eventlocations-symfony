<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The CRM pages lived under `/admin/crm/firmen…` until #54 renamed the company to "Location". Staff
 * bookmarks of the old GET pages answer 301 to the same page under `/admin/crm/locations…`, with
 * the query kept; the browser keeps the fragment (RFC 9110 §10.2.2). POST paths are not redirected:
 * forms post back by route name. The `/admin` response is `no-store`, so no browser caches the 301
 * and a later move stays possible. Another moved CRM page gets one GET action here in this shape.
 */
#[IsGranted('ROLE_EDITOR')]
final class LegacyCompanyPathController extends AbstractController
{
    #[Route('/admin/crm/firmen', name: 'admin_crm_firmen_redirect', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function list(Request $request): RedirectResponse
    {
        return $this->moved('admin_crm_companies', [], $request);
    }

    #[Route('/admin/crm/firmen/neu', name: 'admin_crm_firmen_redirect_new', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function create(Request $request): RedirectResponse
    {
        return $this->moved('admin_crm_companies_new', [], $request);
    }

    #[Route('/admin/crm/firmen/export', name: 'admin_crm_firmen_redirect_export', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    #[IsGranted('ROLE_ADMIN')]
    public function export(Request $request): RedirectResponse
    {
        return $this->moved('admin_crm_companies_export', [], $request);
    }

    #[Route('/admin/crm/firmen/{id}', name: 'admin_crm_firmen_redirect_show', requirements: ['id' => Requirement::UUID], methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function show(string $id, Request $request): RedirectResponse
    {
        return $this->moved('admin_crm_companies_show', ['id' => $id], $request);
    }

    #[Route('/admin/crm/firmen/{id}/deals', name: 'admin_crm_firmen_redirect_deals', requirements: ['id' => Requirement::UUID], methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function deals(string $id, Request $request): RedirectResponse
    {
        return $this->moved('admin_crm_companies_deals', ['id' => $id], $request);
    }

    /**
     * Route parameters win over a query key of the same name.
     *
     * @param array<string, string> $parameters
     */
    private function moved(string $route, array $parameters, Request $request): RedirectResponse
    {
        return $this->redirectToRoute($route, $parameters + $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
    }
}
