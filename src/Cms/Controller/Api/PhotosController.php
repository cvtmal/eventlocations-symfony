<?php

declare(strict_types=1);

namespace App\Cms\Controller\Api;

use App\Cms\Form\PhotoAltData;
use App\Cms\ReadModel\VenuePhotosQuery;
use App\Cms\ValueObject\VenueNumber;
use App\Cms\VenueMediaApi;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\MoveVenuePhotoToRoom;
use App\Venues\Command\RemoveVenuePhoto;
use App\Venues\Command\ReorderVenuePhotos;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The photo manager's writes other than the upload (#25, CMS-6, D11, D13, D21). Each request changes one
 * thing and answers the venue's full photo list `{"photos": [...]}` (PF10), which the island takes as its
 * new state. Bodies:
 *
 * - `PUT …/photos/order` `{"roomId": null|"<uuid>", "ids": ["<uuid>", ...]}`: exactly the scope's ids in
 *   the new order, else 409
 * - `PUT …/photos/{id}/alt` `{"loadedAt": "<token>", "alts": {"<locale>": "<text>"}}`: only the sent
 *   locales change, '' removes one; a stale token is a 409
 * - `PUT …/photos/{id}/room` `{"roomId": null|"<uuid>"}`: null is the gallery
 * - `DELETE …/photos/{id}`
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
#[Route('/admin/api/cms/venues/{number}/photos', requirements: ['number' => VenueNumber::REQUIREMENT, 'id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class PhotosController extends AbstractController
{
    public function __construct(
        private readonly VenuePhotosQuery $photos,
        private readonly VenueMediaApi $api,
        private readonly ValidatorInterface $validator,
        private readonly MessageBusInterface $commandBus,
        private readonly EnabledLocale $locales,
    ) {
    }

    #[Route('/order', name: 'admin_api_cms_photos_order', methods: ['PUT'])]
    public function order(int $number, Request $request): JsonResponse
    {
        $payload = VenueMediaApi::payload($request);
        $roomId = VenueMediaApi::roomId($payload['roomId'] ?? null);
        if (false === $roomId) {
            return $this->api->invalid('roomId', 'cms.photo.room.invalid');
        }
        $ids = VenueMediaApi::ids($payload['ids'] ?? null);
        if (null === $ids) {
            return $this->api->invalid('ids', 'cms.photo.order.invalid');
        }

        return $this->dispatch($number, new ReorderVenuePhotos($number, $roomId, $ids));
    }

    #[Route('/{id}/alt', name: 'admin_api_cms_photos_alt', methods: ['PUT'])]
    public function alt(int $number, string $id, Request $request): JsonResponse
    {
        $data = PhotoAltData::fromPayload(VenueMediaApi::payload($request), $this->locales->all());
        $violations = $this->validator->validate($data);
        if (\count($violations) > 0) {
            return $this->api->violations($violations);
        }

        return $this->dispatch($number, $data->toCommand($number, $id));
    }

    #[Route('/{id}/room', name: 'admin_api_cms_photos_room', methods: ['PUT'])]
    public function room(int $number, string $id, Request $request): JsonResponse
    {
        $payload = VenueMediaApi::payload($request);
        $roomId = \array_key_exists('roomId', $payload) ? VenueMediaApi::roomId($payload['roomId']) : false;
        if (false === $roomId) {
            return $this->api->invalid('roomId', 'cms.photo.room.invalid');
        }

        return $this->dispatch($number, new MoveVenuePhotoToRoom($number, $id, $roomId));
    }

    #[Route('/{id}', name: 'admin_api_cms_photos_remove', methods: ['DELETE'])]
    public function remove(int $number, string $id): JsonResponse
    {
        return $this->dispatch($number, new RemoveVenuePhoto($number, $id));
    }

    private function dispatch(int $number, object $command): JsonResponse
    {
        try {
            $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $failure) {
            return $this->api->failure($failure);
        }

        $view = $this->photos->forVenue($number, $this->locales->default());

        return null === $view ? $this->api->notFound() : new JsonResponse(['photos' => $view->photosArray()]);
    }
}
