<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\Form\PhotoAltData;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ReadModel\VenuePhotosQuery;
use App\Cms\ValueObject\VenueNumber;
use App\Cms\VenueMediaApi;
use App\Cms\VenuePhotoUpload;
use App\Portal\Form\LocationForms;
use App\Portal\Form\PhotoRightsDeclarationData;
use App\Portal\Form\PhotoRightsDeclarationType;
use App\Portal\ReadModel\SubmissionQuery;
use App\Portal\Security\PortalIdentity;
use App\Portal\Security\PortalVenueVoter;
use App\Portal\ValueObject\PortalFlash;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\AddVenuePhoto;
use App\Venues\Command\GrantPhotoRights;
use App\Venues\Exception\VenueNotFound;
use App\Venues\ReadModel\PhotoRightsQuery;
use App\Venues\ReadModel\PhotoRightsView;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The manager's Fotos tab (POR-8): staff's `photo-manager` island without rooms, so it shows the gallery and offers
 * no room. Every write of the island goes through `portal_api_photos_*`.
 *
 * Below the island sit the "Bildrechte" card (G1) and, while a photo has no grant, the declaration form. The page
 * itself takes the form's POST, so an unticked box is a 422 on the same page and a success is a redirect back to it.
 * The declaration grants every photo that has none yet (evidence `declaration`); a revocation has its own route,
 * `LocationPhotoRevokeController`.
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
final class LocationPhotosController extends AbstractController
{
    public function __construct(
        private readonly VenueHeaderQuery $headers,
        private readonly VenuePhotosQuery $photos,
        private readonly PhotoRightsQuery $rights,
        private readonly EnabledLocale $locales,
        private readonly LocationForms $forms,
        private readonly SubmissionQuery $submissions,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/konto/locations/{number}/fotos', name: 'portal_location_photos', requirements: ['number' => VenueNumber::REQUIREMENT], methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(int $number, Request $request, #[CurrentUser] PortalIdentity $identity): Response
    {
        // The declaration is the current manager's own: a colleague's does not cover their uploads.
        $rights = $this->rights->forVenue($number, $this->locales->default(), $identity->id()) ?? throw $this->createNotFoundException();
        $form = $this->createForm(PhotoRightsDeclarationType::class, new PhotoRightsDeclarationData());
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->renderPage($number, $rights, $form);
        }
        if (!$form->isValid()) {
            return $this->renderPage($number, $rights, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Read first: a second click on a stale page would otherwise write an audit row for nothing.
        if (0 === $rights->ungrantedCount) {
            $this->addFlash('success', PortalFlash::message('rights.flash.nothing'));

            return $this->back($number);
        }

        $data = $form->getData();
        \assert($data instanceof PhotoRightsDeclarationData);
        try {
            $granted = $this->commandBus->dispatch(new GrantPhotoRights($number, Grantor::manager($identity->id()), GrantEvidence::Declaration, $data->derivatives))->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failure) {
            if ([] !== $failure->getWrappedExceptions(VenueNotFound::class)) {
                throw $this->createNotFoundException();
            }

            throw $failure;
        }
        $this->addFlash('success', PortalFlash::message('rights.flash.declared', ['count' => \is_int($granted) ? $granted : 0]));

        return $this->back($number);
    }

    private function back(int $number): RedirectResponse
    {
        return $this->redirectToRoute('portal_location_photos', ['number' => $number, '_fragment' => 'bildrechte'], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderPage(int $number, PhotoRightsView $rights, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        $venue = $this->headers->find($number) ?? throw $this->createNotFoundException();
        $view = $this->photos->forVenue($number, $this->locales->default()) ?? throw $this->createNotFoundException();

        return $this->render('portal/location/photos.html.twig', [
            'venue' => $venue,
            'submission' => $this->submissions->find($venue->id),
            'photos' => $view->photosArray(),
            'rooms' => [],
            'rights' => $rights,
            'declaration_form' => $form->createView(),
            'locale_codes' => $this->locales->all(),
            'default_locale' => $this->locales->default(),
            'id_placeholder' => VenueMediaApi::ID_PLACEHOLDER,
            'max_bytes' => VenuePhotoUpload::MAX_BYTES,
            'accept' => implode(',', array_keys(AddVenuePhoto::EXTENSIONS)),
            'alt_max' => PhotoAltData::ALT_MAX,
            'offline_form' => $this->forms->offlineForm($number)->createView(),
        ], new Response(status: $status));
    }
}
