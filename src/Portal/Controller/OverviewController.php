<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The account's start page. It is empty until a location is linked (#43, #44).
 */
final class OverviewController extends AbstractController
{
    #[Route('/konto', name: 'portal_home', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(): Response
    {
        return $this->render('portal/overview.html.twig');
    }
}
