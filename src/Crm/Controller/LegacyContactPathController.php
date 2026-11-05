<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\ReadModel\ContactLocationQuery;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A contact had a page of its own under `/admin/crm/kontakte…` until #55 made it a row on its Location's card. Staff
 * bookmarks answer 301: the list to the Locations list (its filters by kind and owner are gone, so no query is kept),
 * a contact to its Location's Kontakte card; the ids stayed. A contact removed since is a 404, as `/kontakte/neu` is.
 * `/admin/crm/kontakte/export` (the contacts CSV) is a static route and keeps winning over `{id}`. The `/admin`
 * response is `no-store`, so no browser caches the 301 (`LegacyCompanyPathController`).
 */
#[IsGranted('ROLE_EDITOR')]
final class LegacyContactPathController extends AbstractController
{
    #[Route('/admin/crm/kontakte', name: 'admin_crm_kontakte_redirect', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function list(): RedirectResponse
    {
        return $this->redirectToRoute('admin_crm_companies', status: Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/admin/crm/kontakte/{id}', name: 'admin_crm_kontakte_redirect_show', requirements: ['id' => Requirement::UUID], methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function show(string $id, ContactLocationQuery $contacts): RedirectResponse
    {
        $companyId = $contacts->companyIdOf($id) ?? throw $this->createNotFoundException();

        return $this->redirectToRoute('admin_crm_companies_show', ['id' => $companyId, '_fragment' => 'kontakte'], Response::HTTP_MOVED_PERMANENTLY);
    }
}
