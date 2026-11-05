<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Command\RemoveContact;
use App\Crm\Exception\CompanyNotFound;
use App\Crm\Exception\ContactEmailTaken;
use App\Crm\Exception\ContactNotFound;
use App\Crm\Form\ContactData;
use App\Crm\Form\ContactType;
use App\Crm\ReadModel\CompanyRecord;
use App\Crm\ReadModel\CompanyRecordContact;
use App\Crm\ReadModel\CompanyRecordQuery;
use App\Crm\ValueObject\CrmFlash;
use App\Shared\Translation\LocalizedRoutes;
use App\Shared\Uid\EntityId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Kontakte card of a Location (CRM-2, #55): the add and edit dialogs post here, and the edit dialog's "Entfernen".
 * Every answer is a flash and a 303 back to the card (PRG, as the venue link): the dialog has no page of its own to
 * re-render, and the fields' HTML attributes stop most mistakes before the submit. An edit that changes nothing sends
 * no command, so it leaves no audit row (P1).
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/crm/locations/{id}', requirements: ['id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class CompanyContactsController extends AbstractController
{
    /** The form name of the add dialog; each edit dialog's is `contact_<id>`. */
    public const string NEW_FORM = 'contact_new';
    /** The token id of the remove buttons, plain forms because a page holds several. */
    public const string REMOVE_TOKEN = 'submit';

    public function __construct(
        private readonly CompanyRecordQuery $companies,
        private readonly FormFactoryInterface $forms,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    /**
     * The add dialog's form (no contact id) or one contact's edit form, shared with the record page that renders them.
     *
     * @return FormInterface<mixed>
     */
    public static function contactForm(FormFactoryInterface $forms, string $action, ?string $contactId = null, ?ContactData $data = null): FormInterface
    {
        return $forms->createNamed(null === $contactId ? self::NEW_FORM : 'contact_'.$contactId, ContactType::class, $data ?? new ContactData(), ['action' => $action]);
    }

    #[Route('/kontakte', name: 'admin_crm_companies_contacts_create', methods: ['POST'])]
    public function create(string $id, Request $request): RedirectResponse
    {
        $this->company($id);
        $data = new ContactData();
        $form = self::contactForm($this->forms, $this->generateUrl('admin_crm_companies_contacts_create', ['id' => $id]), data: $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', CrmFlash::message('record.contacts.flash.invalid'));
        } else {
            try {
                $this->commandBus->dispatch($data->toCreate(EntityId::generate(), $id));
                $this->addFlash('success', CrmFlash::message('record.contacts.flash.created', ['name' => self::name($data)]));
            } catch (HandlerFailedException $failure) {
                $this->addFlash('error', CrmFlash::message($this->failure($failure)));
            }
        }

        return $this->back($id);
    }

    #[Route('/kontakte/{contactId}', name: 'admin_crm_companies_contacts_edit', requirements: ['contactId' => Requirement::UUID], methods: ['POST'])]
    public function edit(string $id, string $contactId, Request $request): RedirectResponse
    {
        $contact = $this->contact($this->company($id), $contactId) ?? throw $this->createNotFoundException();
        $data = ContactData::fromRecord($contact);
        $form = self::contactForm($this->forms, $this->generateUrl('admin_crm_companies_contacts_edit', ['id' => $id, 'contactId' => $contactId]), $contactId, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', CrmFlash::message('record.contacts.flash.invalid'));
        } elseif (null === $change = $data->toChange($id, $contact)) {
            $this->addFlash('success', CrmFlash::message('record.contacts.flash.unchanged'));
        } else {
            try {
                $this->commandBus->dispatch($change);
                $this->addFlash('success', CrmFlash::message('record.contacts.flash.changed', ['name' => self::name($data)]));
            } catch (HandlerFailedException $failure) {
                $this->addFlash('error', CrmFlash::message($this->failure($failure)));
            }
        }

        return $this->back($id);
    }

    #[Route('/kontakte/{contactId}/entfernen', name: 'admin_crm_companies_contacts_remove', requirements: ['contactId' => Requirement::UUID], methods: ['POST'])]
    public function remove(string $id, string $contactId, Request $request): RedirectResponse
    {
        $contact = $this->contact($this->company($id), $contactId);
        $token = $request->request->all()['_token'] ?? null;
        if (null === $contact || !\is_string($token) || !$this->isCsrfTokenValid(self::REMOVE_TOKEN, $token)) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));
        } else {
            try {
                $this->commandBus->dispatch(new RemoveContact($contactId, $id));
                $this->addFlash('success', CrmFlash::message('record.contacts.flash.removed', ['name' => $contact->fullName()]));
            } catch (HandlerFailedException $failure) {
                $this->addFlash('error', CrmFlash::message($this->failure($failure)));
            }
        }

        return $this->back($id);
    }

    private function company(string $id): CompanyRecord
    {
        return $this->companies->find($id) ?? throw $this->createNotFoundException();
    }

    private function contact(CompanyRecord $company, string $contactId): ?CompanyRecordContact
    {
        foreach ($company->contacts as $contact) {
            if ($contact->id === $contactId) {
                return $contact;
            }
        }

        return null;
    }

    private static function name(ContactData $data): string
    {
        return trim(trim((string) $data->firstName).' '.trim((string) $data->lastName));
    }

    /**
     * A race with another tab: the Location or the contact went between the page and the submit, or another contact of
     * this Location took the e-mail address first.
     */
    private function failure(HandlerFailedException $failure): string
    {
        foreach ($failure->getWrappedExceptions() as $exception) {
            return match (true) {
                $exception instanceof ContactEmailTaken => 'record.contacts.flash.email_taken',
                $exception instanceof ContactNotFound => 'flash.action_failed',
                $exception instanceof CompanyNotFound => throw $this->createNotFoundException(),
                default => throw $failure,
            };
        }

        throw $failure;
    }

    private function back(string $id): RedirectResponse
    {
        return $this->redirectToRoute('admin_crm_companies_show', ['id' => $id, '_fragment' => 'kontakte'], Response::HTTP_SEE_OTHER);
    }
}
