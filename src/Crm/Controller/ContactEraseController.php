<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Admin\Form\ConfirmActionType;
use App\Crm\Command\EraseContact;
use App\Crm\Exception\ContactNotFound;
use App\Crm\ValueObject\CrmFlash;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Erases a contact (CRM-11, D16, admins only), PRG back to the list. The flash names nobody, so the
 * person's name does not outlive the erasure in the session.
 */
#[IsGranted('ROLE_ADMIN')]
final class ContactEraseController extends AbstractController
{
    public const string FORM = 'erase_contact';

    #[Route('/admin/crm/kontakte/{id}/loeschen', name: 'admin_crm_contacts_erase', requirements: ['id' => Requirement::UUID], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $id, Request $request, FormFactoryInterface $forms, MessageBusInterface $commandBus): Response
    {
        $form = $forms->createNamed(self::FORM, ConfirmActionType::class);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));

            return $this->redirectToRoute('admin_crm_contacts_show', ['id' => $id], status: Response::HTTP_SEE_OTHER);
        }

        try {
            $commandBus->dispatch(new EraseContact($id));
        } catch (HandlerFailedException $failure) {
            throw [] !== $failure->getWrappedExceptions(ContactNotFound::class) ? $this->createNotFoundException() : $failure;
        }
        $this->addFlash('success', CrmFlash::message('record.erase.flash'));

        return $this->redirectToRoute('admin_crm_contacts', status: Response::HTTP_SEE_OTHER);
    }
}
