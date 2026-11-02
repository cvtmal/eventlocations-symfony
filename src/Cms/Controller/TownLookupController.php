<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Cms\TownOptions;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The "PLZ / Ort" combobox's source for staff (D1, D22): `TownOptions` answers.
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class TownLookupController extends AbstractController
{
    public function __construct(
        private readonly TownOptions $towns,
    ) {
    }

    #[Route('/admin/api/cms/towns', name: 'admin_api_cms_towns', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse(['options' => $this->towns->forRequest($request)]);
    }
}
