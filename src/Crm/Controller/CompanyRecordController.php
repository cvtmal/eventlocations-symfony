<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Admin\Form\ConfirmActionType;
use App\Admin\Security\AdminIdentity;
use App\Crm\Command\DeleteCompany;
use App\Crm\Command\LinkVenue;
use App\Crm\Command\UnlinkVenue;
use App\Crm\Exception\CompanyHasDeals;
use App\Crm\Exception\CompanyHasVenue;
use App\Crm\Exception\CompanyNotFound;
use App\Crm\Exception\CompanySuppressed;
use App\Crm\Exception\VenueAlreadyLinked;
use App\Crm\Exception\VenueNotFound;
use App\Crm\Form\ContactData;
use App\Crm\Form\LinkVenueData;
use App\Crm\Form\LinkVenueType;
use App\Crm\ReadModel\CompanyRecordContact;
use App\Crm\ReadModel\CompanyRecordQuery;
use App\Crm\ReadModel\DealCardQuery;
use App\Crm\ReadModel\EmailRecipient;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ReadModel\TaskListQuery;
use App\Crm\ReadModel\VenueCompanyQuery;
use App\Crm\TimelineProps;
use App\Crm\ValueObject\CrmFlash;
use App\Crm\ValueObject\RecordKind;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A company record (CRM-1, CRM-3): the page, and the forms its cards post. Assigning and releasing the
 * Eintrag (#56) and deleting the Location are PRG: a refusal is a flash, as #24's status actions. An
 * assignment or release that would change nothing sends no command, so it leaves no audit row (P1); only
 * a true race reaches the handlers' no-ops.
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/crm/locations/{id}', requirements: ['id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class CompanyRecordController extends AbstractController
{
    public const string DELETE_FORM = 'delete_company';
    /** The token id of the Eintrag's release form, a plain form as the contact cards' remove buttons. */
    public const string UNLINK_TOKEN = 'submit';

    public function __construct(
        private readonly CompanyRecordQuery $companies,
        private readonly VenueCompanyQuery $venueCompanies,
        private readonly FormFactoryInterface $forms,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('', name: 'admin_crm_companies_show', methods: ['GET'])]
    public function show(string $id, OwnerOptionsQuery $owners, TimelineProps $timeline, TaskListQuery $tasks, DealCardQuery $deals, #[CurrentUser] AdminIdentity $user): Response
    {
        $company = $this->companies->find($id) ?? throw $this->createNotFoundException();
        $staff = $owners->all();

        return $this->render('admin/crm/company/show.html.twig', [
            'company' => $company,
            'owners' => OwnerOptionsQuery::selectable($staff, $company->ownerId),
            'timeline' => $timeline->of(RecordKind::Company, $id, $user->id(), OwnerOptionsQuery::selectable($staff), EmailRecipient::ofCompany($company), $company->tierNames),
            'tasks' => $tasks->openOf(RecordKind::Company, $id),
            'deals' => $deals->forCompany($id),
            'link_form' => $this->linkForm($id)->createView(),
            'contact_new_form' => CompanyContactsController::contactForm($this->forms, $this->generateUrl('admin_crm_companies_contacts_create', ['id' => $id]))->createView(),
            'contact_forms' => $this->contactForms($id, $company->contacts),
            'delete_form' => $this->isGranted('ROLE_ADMIN') ? $this->deleteForm($id)->createView() : null,
            'unlink_token' => self::UNLINK_TOKEN,
            'remove_token' => CompanyContactsController::REMOVE_TOKEN,
        ]);
    }

    #[Route('/eintraege', name: 'admin_crm_companies_link_venue', methods: ['POST'])]
    public function link(string $id, Request $request): Response
    {
        $data = new LinkVenueData();
        $form = $this->linkForm($id, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', CrmFlash::message('record.venues.flash.invalid'));
        } elseif ($id === $this->venueCompanies->companyIdOf((string) $data->venue)) {
            $this->addFlash('success', CrmFlash::message('record.venues.flash.linked'));
        } else {
            try {
                $this->commandBus->dispatch(new LinkVenue($id, (string) $data->venue));
                $this->addFlash('success', CrmFlash::message('record.venues.flash.linked'));
            } catch (HandlerFailedException $failure) {
                $this->addFlash('error', CrmFlash::message($this->linkFailure($failure)));
            }
        }

        return $this->redirectToRoute('admin_crm_companies_show', ['id' => $id, '_fragment' => 'locations'], status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/eintraege/{venueId}/entfernen', name: 'admin_crm_companies_unlink_venue', requirements: ['venueId' => Requirement::UUID], methods: ['POST'])]
    public function unlink(string $id, string $venueId, Request $request): Response
    {
        $token = $request->request->all()['_token'] ?? null;
        if (!\is_string($token) || !$this->isCsrfTokenValid(self::UNLINK_TOKEN, $token)) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));
        } elseif ($id === $this->venueCompanies->companyIdOf($venueId)) {
            $this->commandBus->dispatch(new UnlinkVenue($id, $venueId));
            $this->addFlash('success', CrmFlash::message('record.venues.flash.unlinked'));
        } elseif (null === $this->companies->name($id)) {
            throw $this->createNotFoundException();
        } else {
            $this->addFlash('error', CrmFlash::message('record.venues.flash.not_linked'));
        }

        return $this->redirectToRoute('admin_crm_companies_show', ['id' => $id, '_fragment' => 'locations'], status: Response::HTTP_SEE_OTHER);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/loeschen', name: 'admin_crm_companies_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $form = $this->deleteForm($id);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));

            return $this->redirectToRoute('admin_crm_companies_show', ['id' => $id], status: Response::HTTP_SEE_OTHER);
        }

        $name = $this->companies->name($id) ?? throw $this->createNotFoundException();
        try {
            $this->commandBus->dispatch(new DeleteCompany($id));
        } catch (HandlerFailedException $failure) {
            if ([] !== $failure->getWrappedExceptions(CompanyHasDeals::class)) {
                $this->addFlash('error', CrmFlash::message('record.delete.has_deals'));

                return $this->redirectToRoute('admin_crm_companies_show', ['id' => $id], status: Response::HTTP_SEE_OTHER);
            }
            if ([] !== $failure->getWrappedExceptions(CompanySuppressed::class)) {
                $this->addFlash('error', CrmFlash::message('record.delete.suppressed'));

                return $this->redirectToRoute('admin_crm_companies_show', ['id' => $id], status: Response::HTTP_SEE_OTHER);
            }

            throw [] !== $failure->getWrappedExceptions(CompanyNotFound::class) ? $this->createNotFoundException() : $failure;
        }
        $this->addFlash('success', CrmFlash::message('record.delete.flash', ['name' => $name]));

        return $this->redirectToRoute('admin_crm_companies', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function linkForm(string $id, ?LinkVenueData $data = null): FormInterface
    {
        return $this->forms->createNamed(LinkVenueType::NAME, LinkVenueType::class, $data ?? new LinkVenueData(), [
            'action' => $this->generateUrl('admin_crm_companies_link_venue', ['id' => $id]),
            'venue_url' => $this->generateUrl('admin_api_crm_venues'),
        ]);
    }

    /**
     * The edit dialog of each contact on the card, prefilled from the record's own statement (no query of its own).
     *
     * @param list<CompanyRecordContact> $contacts
     *
     * @return array<string, FormView>
     */
    private function contactForms(string $id, array $contacts): array
    {
        $views = [];
        foreach ($contacts as $contact) {
            $action = $this->generateUrl('admin_crm_companies_contacts_edit', ['id' => $id, 'contactId' => $contact->id]);
            $views[$contact->id] = CompanyContactsController::contactForm($this->forms, $action, $contact->id, ContactData::fromRecord($contact))->createView();
        }

        return $views;
    }

    /**
     * @return FormInterface<mixed>
     */
    private function deleteForm(string $id): FormInterface
    {
        return $this->forms->createNamed(self::DELETE_FORM, ConfirmActionType::class, null, [
            'action' => $this->generateUrl('admin_crm_companies_delete', ['id' => $id]),
        ]);
    }

    private function linkFailure(HandlerFailedException $failure): string
    {
        foreach ($failure->getWrappedExceptions() as $exception) {
            return match (true) {
                $exception instanceof VenueAlreadyLinked => 'record.venues.flash.already_linked',
                $exception instanceof CompanyHasVenue => 'record.venues.flash.has_venue',
                $exception instanceof VenueNotFound => 'record.venues.flash.venue_not_found',
                $exception instanceof CompanyNotFound => throw $this->createNotFoundException(),
                default => throw $failure,
            };
        }

        throw $failure;
    }
}
