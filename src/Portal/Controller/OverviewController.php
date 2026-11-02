<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ReadModel\VenueListQuery;
use App\Portal\ReadModel\PortalVenueAccessQuery;
use App\Portal\Security\PortalIdentity;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The account's start page: the locations the manager has active access to.
 */
final class OverviewController extends AbstractController
{
    public function __construct(
        private readonly PortalVenueAccessQuery $access,
        private readonly VenueListQuery $venues,
    ) {
    }

    #[Route('/konto', name: 'portal_home', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(#[CurrentUser] PortalIdentity $identity): Response
    {
        return $this->render('portal/overview.html.twig', [
            'locations' => $this->venues->byNumbers($this->access->activeNumbers($identity->id())),
        ]);
    }
}
