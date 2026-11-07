<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ValueObject\VenueNumber;
use App\Portal\Security\PortalIdentity;
use App\Portal\Security\PortalVenueVoter;
use App\Portal\ValueObject\PortalFlash;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\RemoveVenuePhoto;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\ValueObject\Grantor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Freigabe widerrufen" on the Fotos tab (G1): a manager ends the grant of one photo of their own location, which
 * removes the photo from the site and from the gallery (a revoked photo is gone; the grant stays as evidence). A photo
 * that is not there (a double click, a second tab, another location's id) is not an error: the photo is gone, which is
 * what was asked for. It flashes an info and redirects like the success, and changes nothing. POST → redirect → GET
 * with a flash; a missing or wrong token changes nothing, and an unknown venue is a 404.
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
#[Route('/konto/locations/{number}/fotos', requirements: ['number' => VenueNumber::REQUIREMENT, 'id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class LocationPhotoRevokeController extends AbstractController
{
    private const string TOKEN = 'submit';

    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/{id}/freigabe-widerrufen', name: 'portal_location_photos_revoke', methods: ['POST'])]
    public function __invoke(int $number, string $id, Request $request, #[CurrentUser] PortalIdentity $identity): Response
    {
        $token = $request->request->all()['_token'] ?? null;
        if (!\is_string($token) || !$this->isCsrfTokenValid(self::TOKEN, $token)) {
            $this->addFlash('error', PortalFlash::message('rights.flash.failed'));

            return $this->back($number);
        }

        try {
            $this->commandBus->dispatch(new RemoveVenuePhoto($number, $id, Grantor::manager($identity->id())));
        } catch (HandlerFailedException $failure) {
            if ([] !== $failure->getWrappedExceptions(VenueRowNotFound::class)) {
                $this->addFlash('info', PortalFlash::message('rights.flash.gone'));

                return $this->back($number);
            }
            if ([] !== $failure->getWrappedExceptions(VenueNotFound::class)) {
                throw $this->createNotFoundException();
            }

            throw $failure;
        }
        $this->addFlash('success', PortalFlash::message('rights.flash.revoked'));

        return $this->back($number);
    }

    private function back(int $number): Response
    {
        return $this->redirectToRoute('portal_location_photos', ['number' => $number, '_fragment' => 'bildrechte'], Response::HTTP_SEE_OTHER);
    }
}
