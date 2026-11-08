<?php

declare(strict_types=1);

namespace App\Cms;

use App\Cms\Form\RoomData;
use App\Cms\Form\RoomType;
use App\Cms\ReadModel\ManagedRoom;
use App\Cms\ReadModel\VenueRoomsQuery;
use App\Venues\Command\RemoveRoom;
use App\Venues\Command\ReorderRooms;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The room editor's four writes, shared by staff's `Cms\Controller\Api\RoomsController` and the manager's
 * `Portal\Controller\Api\RoomsController` (F11), so access, CSRF and route stay in the controllers and the bodies and
 * answers are one. Each answers the venue's full room list `{"rooms": [...]}` (PF10). Bodies:
 *
 * - add and change: `RoomType`'s fields, the drawer's whole state (a field left out is cleared): `names`/`descriptions`
 *   `{"<locale>": "…"}`, `capacityMin`, `capacityMax`, `areaM2` (integers or null), `heightM` (`"4.20"`, a comma is read
 *   as the point), `floorMaterial` (a FloorMaterial value or null), `seating` `{"<seating>": {"min": n, "max": n}}`;
 *   change also `loadedAt` (the room's token, 409 when stale). No other key: an unknown one is a 422 on its parent
 *   (`''` at the top). Add answers 201 with `"room": "<new id>"` beside the list; over the plan's room limit it is a 422
 *   naming plan and limit (`TierLimits`, F12).
 * - order `{"ids": [...]}`: exactly the venue's room ids in the new order, else 409
 * - remove: the room's photos move to the end of the gallery (D14)
 *
 * Every write goes through a Venues command, whose handler purges the venue's HTTP cache after the commit.
 */
final readonly class VenueRoomsApi
{
    public function __construct(
        private VenueRoomsQuery $rooms,
        private VenueMediaApi $api,
        private MessageBusInterface $commandBus,
        private FormFactoryInterface $forms,
    ) {
    }

    public function add(int $number, Request $request): JsonResponse
    {
        $data = new RoomData();
        $form = $this->forms->create(RoomType::class, $data);
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

    public function order(int $number, Request $request): JsonResponse
    {
        $ids = VenueMediaApi::ids(VenueMediaApi::payload($request)['ids'] ?? null);
        if (null === $ids) {
            return $this->api->invalid('ids', 'cms.room.order.invalid');
        }

        return $this->dispatch($number, new ReorderRooms($number, $ids));
    }

    public function change(int $number, string $id, Request $request): JsonResponse
    {
        $data = new RoomData();
        $form = $this->forms->create(RoomType::class, $data, ['with_token' => true]);
        $form->submit(VenueMediaApi::payload($request));
        if (!$form->isValid()) {
            return $this->api->formErrors($form);
        }

        return $this->dispatch($number, $data->toChangeCommand($number, $id));
    }

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
