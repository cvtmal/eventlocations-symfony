<?php

declare(strict_types=1);

namespace App\Portal\Controller\Api;

use App\Cms\TownOptions;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The "PLZ / Ort" combobox's source for managers: `TownOptions` answers, as for staff.
 */
#[IsGranted('ROLE_PORTAL_USER')]
final class TownLookupController extends AbstractController
{
    public function __construct(
        private readonly TownOptions $towns,
    ) {
    }

    #[Route('/konto/api/towns', name: 'portal_api_towns', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse(['options' => $this->towns->forRequest($request)]);
    }
}
