<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Cms\Form\RoomType;
use App\Cms\ReadModel\ManagedRoom;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ReadModel\VenueRoomsQuery;
use App\Cms\ValueObject\VenueNumber;
use App\Cms\VenueMediaApi;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\Seating;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Räume tab (#25, CMS-5): the host page of the `room-editor` island, which needs JavaScript (S12).
 * The page hands it the rooms, the vocabularies with their labels and the endpoint URLs; every write goes
 * through `admin_api_cms_rooms_*` and answers the whole list (PF10).
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/locations/{number}', requirements: ['number' => VenueNumber::REQUIREMENT], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class VenueRoomsController extends AbstractController
{
    public function __construct(
        private readonly VenueHeaderQuery $headers,
        private readonly VenueRoomsQuery $rooms,
        private readonly EnabledLocale $locales,
    ) {
    }

    #[Route('/raeume', name: 'admin_locations_rooms', methods: ['GET'])]
    public function rooms(int $number): Response
    {
        $venue = $this->headers->find($number) ?? throw $this->createNotFoundException();
        $rooms = $this->rooms->forVenue($number) ?? throw $this->createNotFoundException();

        return $this->render('admin/cms/venue/rooms.html.twig', [
            'venue' => $venue,
            'rooms' => array_map(static fn (ManagedRoom $room): array => $room->toArray(), $rooms),
            'locale_codes' => $this->locales->all(),
            'default_locale' => $this->locales->default(),
            'id_placeholder' => VenueMediaApi::ID_PLACEHOLDER,
            'floor_materials' => FloorMaterial::cases(),
            'seating_types' => Seating::cases(),
            'name_max' => RoomType::NAME_MAX,
            'description_max' => RoomType::DESCRIPTION_MAX,
        ]);
    }
}
