<?php

declare(strict_types=1);

namespace App\Portal\Controller\Api;

use App\Cms\ValueObject\VenueNumber;
use App\Cms\VenueRoomsApi;
use App\Portal\Security\PortalVenueVoter;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A manager's room writes (F11): staff's `room-editor` bodies and answers through `VenueRoomsApi`, behind the venue
 * voter (an `active` access row; G6's competing-claim freeze, PR #31, goes into the same check) and the `portal_api` header token. The
 * plan's room limit is the handler's (`TierLimits`), so a manager gets the same 422 as staff.
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
#[IsCsrfTokenValid('portal_api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
#[Route('/konto/api/locations/{number}/rooms', requirements: ['number' => VenueNumber::REQUIREMENT, 'id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class RoomsController extends AbstractController
{
    public function __construct(
        private readonly VenueRoomsApi $rooms,
    ) {
    }

    #[Route('', name: 'portal_api_rooms_add', methods: ['POST'])]
    public function add(int $number, Request $request): JsonResponse
    {
        return $this->rooms->add($number, $request);
    }

    #[Route('/order', name: 'portal_api_rooms_order', methods: ['PUT'])]
    public function order(int $number, Request $request): JsonResponse
    {
        return $this->rooms->order($number, $request);
    }

    #[Route('/{id}', name: 'portal_api_rooms_change', methods: ['PUT'])]
    public function change(int $number, string $id, Request $request): JsonResponse
    {
        return $this->rooms->change($number, $id, $request);
    }

    #[Route('/{id}', name: 'portal_api_rooms_remove', methods: ['DELETE'])]
    public function remove(int $number, string $id): JsonResponse
    {
        return $this->rooms->remove($number, $id);
    }
}
