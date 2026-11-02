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
use App\Portal\Security\PortalVenueVoter;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\AddVenuePhoto;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The manager's Fotos tab (POR-8): staff's `photo-manager` island without rooms, so it shows the gallery and offers
 * no room. Every write goes through `portal_api_photos_*`.
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
final class LocationPhotosController extends AbstractController
{
    public function __construct(
        private readonly VenueHeaderQuery $headers,
        private readonly VenuePhotosQuery $photos,
        private readonly EnabledLocale $locales,
        private readonly LocationForms $forms,
    ) {
    }

    #[Route('/konto/locations/{number}/fotos', name: 'portal_location_photos', requirements: ['number' => VenueNumber::REQUIREMENT], methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(int $number): Response
    {
        $venue = $this->headers->find($number) ?? throw $this->createNotFoundException();
        $view = $this->photos->forVenue($number, $this->locales->default()) ?? throw $this->createNotFoundException();

        return $this->render('portal/location/photos.html.twig', [
            'venue' => $venue,
            'photos' => $view->photosArray(),
            'rooms' => [],
            'locale_codes' => $this->locales->all(),
            'default_locale' => $this->locales->default(),
            'id_placeholder' => VenueMediaApi::ID_PLACEHOLDER,
            'max_bytes' => VenuePhotoUpload::MAX_BYTES,
            'accept' => implode(',', array_keys(AddVenuePhoto::EXTENSIONS)),
            'alt_max' => PhotoAltData::ALT_MAX,
            'offline_form' => $this->forms->offlineForm($number)->createView(),
        ]);
    }
}
