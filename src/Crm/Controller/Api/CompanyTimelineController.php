<?php

declare(strict_types=1);

namespace App\Crm\Controller\Api;

use App\Admin\Security\AdminIdentity;
use App\Crm\TimelineApi;
use App\Crm\ValueObject\RecordKind;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A page of the company's timeline, `?before=` the last page's `next`, `?type=` a registered type. One route per
 * object (P4): a `company|contact` requirement would make the route walker 404. Reads only; the CSRF attribute is
 * the `/admin/api/` convention (`ExtensionPointsTest`) and leaves a GET unchecked.
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'PATCH', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class CompanyTimelineController extends AbstractController
{
    #[Route('/admin/api/crm/companies/{id}/timeline', name: 'admin_api_crm_company_timeline', requirements: ['id' => Requirement::UUID], methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $id, Request $request, #[CurrentUser] AdminIdentity $user, TimelineApi $timeline): JsonResponse
    {
        return $timeline->page($request, RecordKind::Company, $id, $user->id());
    }
}
