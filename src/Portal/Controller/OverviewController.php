<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ReadModel\VenueListQuery;
use App\Portal\ReadModel\PortalVenueAccessQuery;
use App\Portal\ReadModel\SubmissionQuery;
use App\Portal\Security\PortalIdentity;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The account's start page: the locations the manager has active access to. A manager with no access row at all,
 * not even a pending one, has nothing to see here and starts a new location.
 */
final class OverviewController extends AbstractController
{
    public function __construct(
        private readonly PortalVenueAccessQuery $access,
        private readonly VenueListQuery $venues,
        private readonly SubmissionQuery $submissions,
    ) {
    }

    #[Route('/konto', name: 'portal_home', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(#[CurrentUser] PortalIdentity $identity): Response
    {
        $locations = $this->access->activeLocations($identity->id());
        $numbers = array_keys($locations);
        if ([] === $numbers && !$this->access->hasAnyRow($identity->id())) {
            return $this->redirectToRoute('portal_locations_new');
        }

        return $this->render('portal/overview.html.twig', [
            'locations' => $this->venues->byNumbers($numbers),
            'statuses' => $this->submissions->statuses($numbers),
            'frozen' => array_keys(array_filter($locations)),
        ]);
    }
}
