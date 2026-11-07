<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Admin\Security\AdminIdentity;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ValueObject\CmsFlash;
use App\Cms\ValueObject\VenueNumber;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\GrantPhotoRights;
use App\Venues\Command\RemoveVenuePhoto;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\ReadModel\PhotoRightsQuery;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The two actions of the "Bildrechte" card on the Fotos tab (G1): staff attest the rights of the photos nobody has
 * granted yet ("Alle aktuellen Fotos freigeben"), and revoke the grant of one photo ("Freigabe widerrufen", which
 * removes the photo: a revoked photo is gone). Both are POST → redirect → GET with a flash, like the documents
 * page, and carry the token `cms_photo_rights`: a missing or wrong one changes nothing and flashes
 * `flash.action_failed` (`#[IsCsrfTokenValid]` would send a logged-in editor to the login page, see
 * `VenueDocumentsController`).
 *
 * A revoke whose photo is not there (a double click, a second tab, another venue's id) is not an error: the photo is
 * gone, which is what was asked for. It flashes an info and redirects like the success, and changes nothing. An unknown
 * venue stays a 404.
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/locations/{number}/fotos', requirements: ['number' => VenueNumber::REQUIREMENT, 'id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class VenuePhotoRightsController extends AbstractController
{
    public const string CSRF_ID = 'cms_photo_rights';

    public function __construct(
        private readonly VenueHeaderQuery $headers,
        private readonly PhotoRightsQuery $rights,
        private readonly EnabledLocale $locales,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/freigeben', name: 'admin_locations_photos_grant', methods: ['POST'])]
    public function grant(int $number, Request $request, #[CurrentUser] AdminIdentity $identity): Response
    {
        $this->assertVenueExists($number);
        if (!$this->tokenIsValid($request)) {
            return $this->back($number);
        }

        // Read first: a stale page's second click would otherwise write an audit row for nothing.
        $view = $this->rights->forVenue($number, $this->locales->default()) ?? throw $this->createNotFoundException();
        if (0 === $view->ungrantedCount) {
            $this->addFlash('success', CmsFlash::message('photos.rights.flash.nothing'));

            return $this->back($number);
        }

        try {
            $granted = $this->commandBus->dispatch(new GrantPhotoRights($number, Grantor::staff($identity->id()), GrantEvidence::StaffAttestation))->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failure) {
            $this->rethrowNotFound($failure);

            throw $failure;
        }
        $this->addFlash('success', CmsFlash::message('photos.rights.flash.granted', ['count' => \is_int($granted) ? $granted : 0]));

        return $this->back($number);
    }

    #[Route('/{id}/freigabe-widerrufen', name: 'admin_locations_photos_revoke', methods: ['POST'])]
    public function revoke(int $number, string $id, Request $request, #[CurrentUser] AdminIdentity $identity): Response
    {
        $this->assertVenueExists($number);
        if (!$this->tokenIsValid($request)) {
            return $this->back($number);
        }

        try {
            $this->commandBus->dispatch(new RemoveVenuePhoto($number, $id, Grantor::staff($identity->id())));
        } catch (HandlerFailedException $failure) {
            if ([] !== $failure->getWrappedExceptions(VenueRowNotFound::class)) {
                $this->addFlash('info', CmsFlash::message('photos.rights.flash.gone'));

                return $this->back($number);
            }
            $this->rethrowNotFound($failure);

            throw $failure;
        }
        $this->addFlash('success', CmsFlash::message('photos.rights.flash.revoked'));

        return $this->back($number);
    }

    private function assertVenueExists(int $number): void
    {
        if (null === $this->headers->find($number)) {
            throw $this->createNotFoundException();
        }
    }

    private function tokenIsValid(Request $request): bool
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, $request->getPayload()->getString('_token'))) {
            return true;
        }
        $this->addFlash('error', 'flash.action_failed');

        return false;
    }

    private function rethrowNotFound(HandlerFailedException $failure): void
    {
        if ([] !== $failure->getWrappedExceptions(VenueNotFound::class) || [] !== $failure->getWrappedExceptions(VenueRowNotFound::class)) {
            throw $this->createNotFoundException();
        }
    }

    private function back(int $number): RedirectResponse
    {
        return $this->redirectToRoute('admin_locations_photos', ['number' => $number, '_fragment' => 'bildrechte'], Response::HTTP_SEE_OTHER);
    }
}
