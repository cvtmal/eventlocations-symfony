<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ReadModel\VenueHeader;
use App\Cms\ValueObject\VenueNumber;
use App\Cms\ValueObject\VideoPagesSetup;
use App\Cms\VenueVideoPages;
use App\Portal\Form\LocationForms;
use App\Portal\ReadModel\LocationPlanQuery;
use App\Portal\ReadModel\SubmissionQuery;
use App\Portal\Security\PortalVenueVoter;
use App\Shared\Translation\LocalizedRoutes;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The manager's Videos tab (G15): staff's pages of `VenueVideoPages` (link, order, removal; no JavaScript) inside the
 * location layout, with the plan card in the aside, since the plan caps the videos (F12). Access is `PortalVenueVoter`,
 * so every route here follows the voter's rules (an inactive access and G6's freeze lock the manager out).
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
#[Route('/konto/locations/{number}/videos', requirements: ['number' => VenueNumber::REQUIREMENT, 'id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class LocationVideosController extends AbstractController
{
    public function __construct(
        private readonly VenueVideoPages $pages,
        private readonly LocationForms $forms,
        private readonly SubmissionQuery $submissions,
        private readonly LocationPlanQuery $plans,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'portal_location_videos', methods: ['GET', 'POST'])]
    public function index(int $number, Request $request): Response
    {
        return $this->pages->index($this->setup(), $number, $request);
    }

    #[Route('/{id}/nach-oben', name: 'portal_location_videos_up', methods: ['POST'])]
    public function up(int $number, string $id, Request $request): Response
    {
        return $this->pages->move($this->setup(), $number, $id, -1, $request);
    }

    #[Route('/{id}/nach-unten', name: 'portal_location_videos_down', methods: ['POST'])]
    public function down(int $number, string $id, Request $request): Response
    {
        return $this->pages->move($this->setup(), $number, $id, 1, $request);
    }

    #[Route('/{id}/loeschen', name: 'portal_location_videos_remove', methods: ['POST'])]
    public function remove(int $number, string $id, Request $request): Response
    {
        return $this->pages->remove($this->setup(), $number, $id, $request);
    }

    private function setup(): VideoPagesSetup
    {
        return new VideoPagesSetup(
            'portal_location_videos',
            'portal_video',
            'portal/location/videos.html.twig',
            fn (VenueHeader $venue): array => [
                'submission' => $this->submissions->find($venue->id),
                'offline_form' => $this->forms->offlineForm($venue->number)->createView(),
                'plan' => $this->plans->forVenue($venue->id, $this->clock->now()),
            ],
        );
    }
}
