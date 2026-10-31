<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Command\CreateCompanyForVenue;
use App\Crm\Exception\ContactEmailTaken;
use App\Crm\Exception\VenueNotFound;
use App\Crm\ReadModel\VenueCompanyQuery;
use App\Crm\ValueObject\BootstrapOutcome;
use App\Crm\ValueObject\CrmFlash;
use App\Shared\Translation\LocalizedRoutes;
use App\Shared\Uid\EntityId;
use App\Venues\ReadModel\VenueContactFactsQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The venue panel's "Firma anlegen" (`### 27.`, D19): the bootstrap's command for one venue, PRG back
 * to the editor, whichever tab the panel was on. The path takes the venue id (P4); the editor's URL
 * takes the number, a route of the back office and no PHP edge to Cms. A stale tab sends no command,
 * or the audit log would name a company that never existed; only a true race reaches the handler's skip.
 */
#[IsGranted('ROLE_EDITOR')]
final class CompanyFromVenueController extends AbstractController
{
    public const string TOKEN = 'submit';

    #[Route('/admin/crm/locations/{venueId}/firma', name: 'admin_crm_company_from_venue', requirements: ['venueId' => Requirement::UUID], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $venueId, Request $request, VenueContactFactsQuery $venues, VenueCompanyQuery $companies, MessageBusInterface $commandBus): Response
    {
        $venue = $venues->find($venueId) ?? throw $this->createNotFoundException();

        $token = $request->request->all()['_token'] ?? null;
        if (!\is_string($token) || !$this->isCsrfTokenValid(self::TOKEN, $token)) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));
        } elseif (null !== $companies->companyIdOf($venueId)) {
            $this->addFlash('success', CrmFlash::message('panel.flash.already'));
        } else {
            $this->create($venueId, $commandBus);
        }

        return $this->redirectToRoute('admin_locations_edit', ['number' => $venue->number, '_fragment' => 'crm'], status: Response::HTTP_SEE_OTHER);
    }

    private function create(string $venueId, MessageBusInterface $commandBus): void
    {
        try {
            $outcome = $commandBus->dispatch(new CreateCompanyForVenue($venueId, EntityId::generate()))->last(HandledStamp::class)?->getResult();
            \assert($outcome instanceof BootstrapOutcome);
            $this->addFlash('success', CrmFlash::message($outcome->isSkipped() ? 'panel.flash.already' : 'panel.flash.created'));
        } catch (HandlerFailedException $failure) {
            if ([] !== $failure->getWrappedExceptions(VenueNotFound::class)) {
                throw $this->createNotFoundException();
            }
            if ([] === $failure->getWrappedExceptions(ContactEmailTaken::class)) {
                throw $failure;
            }
            $this->addFlash('error', CrmFlash::message('panel.flash.email_taken'));
        }
    }
}
