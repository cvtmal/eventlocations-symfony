<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Shared\Translation\LocalizedRoutes;
use App\Venues\ReadModel\ModerationCaseQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Meldungen" (G2): the complaints of the public form. Open cases first, the oldest on top (they have waited
 * longest), then the latest decided ones. At most two staff and a handful of cases, so no paging or filters.
 */
#[IsGranted('ROLE_EDITOR')]
final class ModerationListController extends AbstractController
{
    /** How many decided cases the queue shows below the open ones. */
    public const int DECIDED_LIMIT = 50;

    #[Route('/admin/meldungen', name: 'admin_moderation', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(ModerationCaseQuery $cases): Response
    {
        return $this->render('admin/cms/moderation/index.html.twig', [
            'open' => $cases->open(),
            'decided' => $cases->decided(self::DECIDED_LIMIT),
        ]);
    }
}
