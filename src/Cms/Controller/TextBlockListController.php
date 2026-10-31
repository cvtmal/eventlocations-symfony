<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Cms\ReadModel\ContentBlockStatusQuery;
use App\Shared\Extension\ContentBlockRegistry;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The declared text blocks (#26): every `ContentBlockDefinition`, by key, with whether staff wrote a text and
 * when. A handful of rows, so no paging.
 */
#[IsGranted('ROLE_EDITOR')]
final class TextBlockListController extends AbstractController
{
    #[Route('/admin/textbausteine', name: 'admin_text_blocks', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(ContentBlockRegistry $blocks, ContentBlockStatusQuery $status): Response
    {
        $changes = $status->byPrefix('');
        $rows = [];
        foreach ($blocks->all() as $definition) {
            $rows[] = ['definition' => $definition, 'updated_at' => $changes->of($definition->key())];
        }

        return $this->render('admin/cms/pages/block_list.html.twig', ['rows' => $rows]);
    }
}
