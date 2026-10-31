<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Admin\Form\ConfirmActionType;
use App\Admin\Security\AdminIdentity;
use App\Crm\ReadModel\ContactRecordQuery;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ReadModel\TaskListQuery;
use App\Crm\TimelineProps;
use App\Crm\ValueObject\RecordKind;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A contact record (CRM-2, CRM-3): the properties card with the inline editor, the Firma and Aufgaben cards.
 * Export and erasure (A6) sit in its `Aktionen`.
 */
#[IsGranted('ROLE_EDITOR')]
final class ContactRecordController extends AbstractController
{
    #[Route('/admin/crm/kontakte/{id}', name: 'admin_crm_contacts_show', requirements: ['id' => Requirement::UUID], methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $id, ContactRecordQuery $contacts, OwnerOptionsQuery $owners, FormFactoryInterface $forms, TimelineProps $timeline, TaskListQuery $tasks, #[CurrentUser] AdminIdentity $user): Response
    {
        $contact = $contacts->find($id) ?? throw $this->createNotFoundException();
        $staff = $owners->all();

        return $this->render('admin/crm/contact/show.html.twig', [
            'contact' => $contact,
            'owners' => OwnerOptionsQuery::selectable($staff, $contact->ownerId),
            'timeline' => $timeline->of(RecordKind::Contact, $id, $user->id(), OwnerOptionsQuery::selectable($staff)),
            'tasks' => $tasks->openOf(RecordKind::Contact, $id),
            'erase_form' => $this->isGranted('ROLE_ADMIN')
                ? $forms->createNamed(ContactEraseController::FORM, ConfirmActionType::class, null, ['action' => $this->generateUrl('admin_crm_contacts_erase', ['id' => $id])])->createView()
                : null,
        ]);
    }
}
