<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Cms\Controller\Api\PhotoUploadController;
use App\Cms\Form\PhotoAltData;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ReadModel\VenuePhotosQuery;
use App\Cms\ValueObject\VenueNumber;
use App\Cms\VenueMediaApi;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\AddVenuePhoto;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Fotos tab (#25, CMS-6): the host page of the `photo-manager` island, which needs JavaScript (S12).
 * The page hands it the photos, the rooms and the endpoint URLs; every write goes through
 * `admin_api_cms_photos_*` and answers the whole list (PF10).
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/locations/{number}', requirements: ['number' => VenueNumber::REQUIREMENT], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class VenuePhotosController extends AbstractController
{
    public function __construct(
        private readonly VenueHeaderQuery $headers,
        private readonly VenuePhotosQuery $photos,
        private readonly EnabledLocale $locales,
    ) {
    }

    #[Route('/fotos', name: 'admin_locations_photos', methods: ['GET'])]
    public function photos(int $number): Response
    {
        $venue = $this->headers->find($number) ?? throw $this->createNotFoundException();
        $view = $this->photos->forVenue($number, $this->locales->default()) ?? throw $this->createNotFoundException();

        return $this->render('admin/cms/venue/photos.html.twig', [
            'venue' => $venue,
            'photos' => $view->photosArray(),
            'rooms' => $view->roomsArray(),
            'locale_codes' => $this->locales->all(),
            'default_locale' => $this->locales->default(),
            'id_placeholder' => VenueMediaApi::ID_PLACEHOLDER,
            'max_bytes' => PhotoUploadController::MAX_BYTES,
            'accept' => implode(',', array_keys(AddVenuePhoto::EXTENSIONS)),
            'alt_max' => PhotoAltData::ALT_MAX,
        ]);
    }
}
