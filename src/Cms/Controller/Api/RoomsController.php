<?php

declare(strict_types=1);

namespace App\Cms\Controller\Api;

use App\Cms\ValueObject\VenueNumber;
use App\Cms\VenueRoomsApi;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The room editor's writes (#25, CMS-5, D14, D21, D23) for staff; bodies and answers are `VenueRoomsApi`'s, which the
 * manager's `Portal\Controller\Api\RoomsController` shares (F11).
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
#[Route('/admin/api/cms/venues/{number}/rooms', requirements: ['number' => VenueNumber::REQUIREMENT, 'id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class RoomsController extends AbstractController
{
    public function __construct(
        private readonly VenueRoomsApi $rooms,
    ) {
    }

    #[Route('', name: 'admin_api_cms_rooms_add', methods: ['POST'])]
    public function add(int $number, Request $request): JsonResponse
    {
        return $this->rooms->add($number, $request);
    }

    #[Route('/order', name: 'admin_api_cms_rooms_order', methods: ['PUT'])]
    public function order(int $number, Request $request): JsonResponse
    {
        return $this->rooms->order($number, $request);
    }

    #[Route('/{id}', name: 'admin_api_cms_rooms_change', methods: ['PUT'])]
    public function change(int $number, string $id, Request $request): JsonResponse
    {
        return $this->rooms->change($number, $id, $request);
    }

    #[Route('/{id}', name: 'admin_api_cms_rooms_remove', methods: ['DELETE'])]
    public function remove(int $number, string $id): JsonResponse
    {
        return $this->rooms->remove($number, $id);
    }
}
