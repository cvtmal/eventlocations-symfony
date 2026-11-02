<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ValueObject\CmsFlash;
use App\Cms\ValueObject\VenueNumber;
use App\Portal\Form\LocationForms;
use App\Portal\Security\PortalVenueVoter;
use App\Portal\ValueObject\PortalFlash;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\UnpublishVenue;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A manager takes a location offline (POR-10). It is the one way: no `/konto` route publishes, going back online is
 * staff's. A location already offline dispatches nothing, so a double click leaves no second audit row.
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
final class LocationOfflineController extends AbstractController
{
    public function __construct(
        private readonly VenueHeaderQuery $headers,
        private readonly LocationForms $forms,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/konto/locations/{number}/offline', name: 'portal_location_offline', requirements: ['number' => VenueNumber::REQUIREMENT], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(int $number, Request $request): Response
    {
        $venue = $this->headers->find($number) ?? throw $this->createNotFoundException();

        $form = $this->forms->offlineForm($number);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', PortalFlash::message('editor.offline.failed'));
        } elseif (VenueStatus::Published !== $venue->status) {
            $this->addFlash('success', CmsFlash::message('publish.flash.already_unpublished', ['name' => $venue->name]));
        } else {
            $this->commandBus->dispatch(new UnpublishVenue($number));
            $this->addFlash('success', CmsFlash::message('publish.flash.unpublished', ['name' => $venue->name]));
        }

        return $this->redirectToRoute('portal_location', ['number' => $number]);
    }
}
