<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ReadModel\VenueHeader;
use App\Cms\ValueObject\DocumentPagesSetup;
use App\Cms\ValueObject\VenueNumber;
use App\Cms\VenueDocumentPages;
use App\Portal\Form\LocationForms;
use App\Portal\ReadModel\LocationPlanQuery;
use App\Portal\ReadModel\SubmissionQuery;
use App\Portal\Security\PortalIdentity;
use App\Portal\Security\PortalVenueVoter;
use App\Shared\Translation\LocalizedRoutes;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The manager's Dokumente tab (F11): staff's pages of `VenueDocumentPages` (upload, titles, order, removal; no
 * JavaScript) inside the location layout, with the plan card beside them, since the plan caps the PDFs (F12). An upload
 * counts against the manager's `portal_upload` budget, the one the photos use (every attempt, staff are exempt).
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
#[Route('/konto/locations/{number}/dokumente', requirements: ['number' => VenueNumber::REQUIREMENT, 'id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class LocationDocumentsController extends AbstractController
{
    public function __construct(
        private readonly VenueDocumentPages $pages,
        private readonly LocationForms $forms,
        private readonly SubmissionQuery $submissions,
        private readonly LocationPlanQuery $plans,
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactoryInterface $portalUploadLimiter,
    ) {
    }

    #[Route('', name: 'portal_location_documents', methods: ['GET', 'POST'])]
    public function index(int $number, Request $request, #[CurrentUser] PortalIdentity $identity): Response
    {
        return $this->pages->index($this->setup($identity), $number, $request);
    }

    #[Route('/{id}', name: 'portal_location_documents_edit', methods: ['GET', 'POST'])]
    public function edit(int $number, string $id, Request $request, #[CurrentUser] PortalIdentity $identity): Response
    {
        return $this->pages->edit($this->setup($identity), $number, $id, $request);
    }

    #[Route('/{id}/nach-oben', name: 'portal_location_documents_up', methods: ['POST'])]
    public function up(int $number, string $id, Request $request, #[CurrentUser] PortalIdentity $identity): Response
    {
        return $this->pages->move($this->setup($identity), $number, $id, -1, $request);
    }

    #[Route('/{id}/nach-unten', name: 'portal_location_documents_down', methods: ['POST'])]
    public function down(int $number, string $id, Request $request, #[CurrentUser] PortalIdentity $identity): Response
    {
        return $this->pages->move($this->setup($identity), $number, $id, 1, $request);
    }

    #[Route('/{id}/loeschen', name: 'portal_location_documents_remove', methods: ['POST'])]
    public function remove(int $number, string $id, Request $request, #[CurrentUser] PortalIdentity $identity): Response
    {
        return $this->pages->remove($this->setup($identity), $number, $id, $request);
    }

    private function setup(PortalIdentity $identity): DocumentPagesSetup
    {
        return new DocumentPagesSetup(
            'portal_location_documents',
            'portal_document',
            'portal/location/documents.html.twig',
            'portal/location/document_edit.html.twig',
            fn (VenueHeader $venue): array => [
                'submission' => $this->submissions->find($venue->id),
                'offline_form' => $this->forms->offlineForm($venue->number)->createView(),
                'plan' => $this->plans->forVenue($venue->id, $this->clock->now()),
            ],
            fn (): ?string => $this->portalUploadLimiter->create($identity->id())->consume()->isAccepted() ? null : 'portal.document.rate_limited',
        );
    }
}
