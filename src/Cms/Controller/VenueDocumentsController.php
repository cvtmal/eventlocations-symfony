<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Cms\ValueObject\DocumentPagesSetup;
use App\Cms\ValueObject\VenueNumber;
use App\Cms\VenueDocumentPages;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Staff's Dokumente tab (#25, CMS-7, D22): the pages of `VenueDocumentPages`, which the manager's tab shares (F11).
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/locations/{number}/dokumente', requirements: ['number' => VenueNumber::REQUIREMENT, 'id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class VenueDocumentsController extends AbstractController
{
    public function __construct(
        private readonly VenueDocumentPages $pages,
    ) {
    }

    #[Route('', name: 'admin_locations_documents', methods: ['GET', 'POST'])]
    public function index(int $number, Request $request): Response
    {
        return $this->pages->index(self::setup(), $number, $request);
    }

    #[Route('/{id}', name: 'admin_locations_documents_edit', methods: ['GET', 'POST'])]
    public function edit(int $number, string $id, Request $request): Response
    {
        return $this->pages->edit(self::setup(), $number, $id, $request);
    }

    #[Route('/{id}/nach-oben', name: 'admin_locations_documents_up', methods: ['POST'])]
    public function up(int $number, string $id, Request $request): Response
    {
        return $this->pages->move(self::setup(), $number, $id, -1, $request);
    }

    #[Route('/{id}/nach-unten', name: 'admin_locations_documents_down', methods: ['POST'])]
    public function down(int $number, string $id, Request $request): Response
    {
        return $this->pages->move(self::setup(), $number, $id, 1, $request);
    }

    #[Route('/{id}/loeschen', name: 'admin_locations_documents_remove', methods: ['POST'])]
    public function remove(int $number, string $id, Request $request): Response
    {
        return $this->pages->remove(self::setup(), $number, $id, $request);
    }

    private static function setup(): DocumentPagesSetup
    {
        return new DocumentPagesSetup(
            'admin_locations_documents',
            'cms_document',
            'admin/cms/venue/documents.html.twig',
            'admin/cms/venue/document_edit.html.twig',
            static fn (): array => [],
        );
    }
}
