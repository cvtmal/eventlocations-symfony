<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Shared\Translation\LocalizedRoutes;
use App\Venues\ReadModel\TierDefinitionsQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The tiers (F12): every row of `tiers`, best first, with its limits and flags and how many venues are on it. A
 * handful of rows, so no paging, no filter and no sort.
 */
#[IsGranted('ROLE_EDITOR')]
final class TierListController extends AbstractController
{
    #[Route('/admin/tarife', name: 'admin_tiers', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(TierDefinitionsQuery $tiers): Response
    {
        return $this->render('admin/cms/tiers/index.html.twig', ['rows' => $tiers->all()]);
    }
}
