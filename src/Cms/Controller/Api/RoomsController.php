<?php

declare(strict_types=1);

namespace App\Cms\Controller\Api;

use App\Cms\Form\RoomData;
use App\Cms\Form\RoomType;
use App\Cms\ReadModel\ManagedRoom;
use App\Cms\ReadModel\VenueRoomsQuery;
use App\Cms\ValueObject\VenueNumber;
use App\Cms\VenueMediaApi;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\RemoveRoom;
use App\Venues\Command\ReorderRooms;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The room editor's writes (#25, CMS-5, D14, D21, D23). Each answers the venue's full room list
 * `{"rooms": [...]}` (PF10). Bodies:
 *
 * - `POST …/rooms` and `PUT …/rooms/{id}`: `RoomType`'s fields, the drawer's whole state (a field left out
 *   is cleared): `names`/`descriptions` `{"<locale>": "…"}`, `capacityMin`, `capacityMax`, `areaM2`
 *   (integers or null), `heightM` (`"4.20"`, a comma is read as the point), `floorMaterial` (a
 *   FloorMaterial value or null), `seating` `{"<seating>": {"min": n, "max": n}}`; the PUT also
 *   `loadedAt` (the room's token, 409 when stale). No other key: an unknown one is a 422 on its parent
 *   (`''` at the top). The POST answers 201 with `"room": "<new id>"` beside the list.
 * - `PUT …/rooms/order` `{"ids": [...]}`: exactly the venue's room ids in the new order, else 409
 * - `DELETE …/rooms/{id}`: the room's photos move to the end of the gallery (D14)
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
#[Route('/admin/api/cms/venues/{number}/rooms', requirements: ['number' => VenueNumber::REQUIREMENT, 'id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class RoomsController extends AbstractController
{
    public function __construct(
        private readonly VenueRoomsQuery $rooms,
        private readonly VenueMediaApi $api,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('', name: 'admin_api_cms_rooms_add', methods: ['POST'])]
    public function add(int $number, Request $request): JsonResponse
    {
        $data = new RoomData();
        $form = $this->createForm(RoomType::class, $data);
        $form->submit(VenueMediaApi::payload($request));
        if (!$form->isValid()) {
            return $this->api->formErrors($form);
        }

        try {
            $roomId = $this->commandBus->dispatch($data->toAddCommand($number))->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failure) {
            return $this->api->failure($failure);
        }
        \assert(\is_string($roomId));

        return $this->answer($number, ['room' => $roomId], Response::HTTP_CREATED);
    }

    #[Route('/order', name: 'admin_api_cms_rooms_order', methods: ['PUT'])]
    public function order(int $number, Request $request): JsonResponse
    {
        $ids = VenueMediaApi::ids(VenueMediaApi::payload($request)['ids'] ?? null);
        if (null === $ids) {
            return $this->api->invalid('ids', 'cms.room.order.invalid');
        }

        return $this->dispatch($number, new ReorderRooms($number, $ids));
    }

    #[Route('/{id}', name: 'admin_api_cms_rooms_change', methods: ['PUT'])]
    public function change(int $number, string $id, Request $request): JsonResponse
    {
        $data = new RoomData();
        $form = $this->createForm(RoomType::class, $data, ['with_token' => true]);
        $form->submit(VenueMediaApi::payload($request));
        if (!$form->isValid()) {
            return $this->api->formErrors($form);
        }

        return $this->dispatch($number, $data->toChangeCommand($number, $id));
    }

    #[Route('/{id}', name: 'admin_api_cms_rooms_remove', methods: ['DELETE'])]
    public function remove(int $number, string $id): JsonResponse
    {
        return $this->dispatch($number, new RemoveRoom($number, $id));
    }

    private function dispatch(int $number, object $command): JsonResponse
    {
        try {
            $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $failure) {
            return $this->api->failure($failure);
        }

        return $this->answer($number);
    }

    /**
     * @param array<string, string> $extra
     */
    private function answer(int $number, array $extra = [], int $status = Response::HTTP_OK): JsonResponse
    {
        $rooms = $this->rooms->forVenue($number);
        if (null === $rooms) {
            return $this->api->notFound();
        }

        return new JsonResponse($extra + ['rooms' => array_map(static fn (ManagedRoom $room): array => $room->toArray(), $rooms)], $status);
    }
}
