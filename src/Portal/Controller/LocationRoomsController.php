<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\Form\RoomType;
use App\Cms\ReadModel\ManagedRoom;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ReadModel\VenueRoomsQuery;
use App\Cms\ValueObject\VenueNumber;
use App\Cms\VenueMediaApi;
use App\Portal\Form\LocationForms;
use App\Portal\ReadModel\LocationPlanQuery;
use App\Portal\ReadModel\SubmissionQuery;
use App\Portal\Security\PortalVenueVoter;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\Seating;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The manager's Räume tab (F11): staff's `room-editor` island with the `portal_api_rooms_*` endpoints, and the plan
 * card beside it, since the plan caps the rooms (F12). The island needs JavaScript (S12), as on staff's tab.
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
final class LocationRoomsController extends AbstractController
{
    public function __construct(
        private readonly VenueHeaderQuery $headers,
        private readonly VenueRoomsQuery $rooms,
        private readonly EnabledLocale $locales,
        private readonly LocationForms $forms,
        private readonly SubmissionQuery $submissions,
        private readonly LocationPlanQuery $plans,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/konto/locations/{number}/raeume', name: 'portal_location_rooms', requirements: ['number' => VenueNumber::REQUIREMENT], methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(int $number): Response
    {
        $venue = $this->headers->find($number) ?? throw $this->createNotFoundException();
        $rooms = $this->rooms->forVenue($number) ?? throw $this->createNotFoundException();

        return $this->render('portal/location/rooms.html.twig', [
            'venue' => $venue,
            'submission' => $this->submissions->find($venue->id),
            'offline_form' => $this->forms->offlineForm($number)->createView(),
            'plan' => $this->plans->forVenue($venue->id, $this->clock->now()),
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
