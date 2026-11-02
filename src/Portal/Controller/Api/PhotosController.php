<?php

declare(strict_types=1);

namespace App\Portal\Controller\Api;

use App\Cms\Form\PhotoAltData;
use App\Cms\ReadModel\VenuePhotosQuery;
use App\Cms\ValueObject\VenueNumber;
use App\Cms\VenueMediaApi;
use App\Portal\Security\PortalVenueVoter;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
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
 * A manager's photo writes other than the upload (POR-8), with the bodies and answers of staff's
 * `Cms\Controller\Api\PhotosController`. Rooms stay with staff: `order` works on the gallery whatever the body says,
 * and there is no room action.
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
#[IsCsrfTokenValid('portal_api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
#[Route('/konto/api/locations/{number}/photos', requirements: ['number' => VenueNumber::REQUIREMENT, 'id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
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

    #[Route('/order', name: 'portal_api_photos_order', methods: ['PUT'])]
    public function order(int $number, Request $request): JsonResponse
    {
        $ids = VenueMediaApi::ids(VenueMediaApi::payload($request)['ids'] ?? null);
        if (null === $ids) {
            return $this->api->invalid('ids', 'cms.photo.order.invalid');
        }

        return $this->dispatch($number, new ReorderVenuePhotos($number, null, $ids));
    }

    #[Route('/{id}/alt', name: 'portal_api_photos_alt', methods: ['PUT'])]
    public function alt(int $number, string $id, Request $request): JsonResponse
    {
        $data = PhotoAltData::fromPayload(VenueMediaApi::payload($request), $this->locales->all());
        $violations = $this->validator->validate($data);
        if (\count($violations) > 0) {
            return $this->api->violations($violations);
        }

        return $this->dispatch($number, $data->toCommand($number, $id));
    }

    #[Route('/{id}', name: 'portal_api_photos_remove', methods: ['DELETE'])]
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
