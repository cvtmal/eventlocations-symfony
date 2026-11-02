<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Admin\Form\ConfirmActionType;
use App\Crm\Command\DeleteEmailTemplate;
use App\Crm\Command\SaveEmailTemplate;
use App\Crm\Exception\EmailTemplateNameTaken;
use App\Crm\Exception\EmailTemplateNotFound;
use App\Crm\Form\EmailTemplateData;
use App\Crm\Form\EmailTemplateType;
use App\Crm\ReadModel\EmailTemplateQuery;
use App\Crm\ValueObject\CrmFlash;
use App\Shared\Translation\LocalizedRoutes;
use App\Shared\Uid\EntityId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The mail templates of the settings screen (CRM-10): admins only. `requiredRole()` of the sidebar item only hides
 * the link; this class is the gate. A refused form re-renders with 422 through `render()`.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/crm/vorlagen', options: [LocalizedRoutes::UNLOCALIZED => true])]
final class EmailTemplatesController extends AbstractController
{
    private const string DELETE_FORM = 'delete_email_template';

    public function __construct(
        private readonly EmailTemplateQuery $templates,
        private readonly MessageBusInterface $commandBus,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'admin_crm_email_templates', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/crm/email_template/index.html.twig', ['rows' => $this->templates->all()]);
    }

    #[Route('/neu', name: 'admin_crm_email_templates_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new EmailTemplateData();
        $form = $this->createForm(EmailTemplateType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->save($data->toCommand(EntityId::generate()), $form)) {
            return $this->savedResponse();
        }

        return $this->render('admin/crm/email_template/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}', name: 'admin_crm_email_templates_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $template = $this->templates->find($id) ?? throw $this->createNotFoundException();
        $data = EmailTemplateData::fromRow($template);
        $form = $this->createForm(EmailTemplateType::class, $data);
        $form->handleRequest($request);

        // An unchanged save dispatches nothing, so it leaves no audit row (P1).
        if ($form->isSubmitted() && $form->isValid() && ($data->matches($template) || $this->save($data->toCommand($id), $form))) {
            return $this->savedResponse();
        }

        return $this->render('admin/crm/email_template/edit.html.twig', [
            'email_template' => $template,
            'form' => $form,
            'delete_form' => $this->deleteForm($id),
        ]);
    }

    #[Route('/{id}/loeschen', name: 'admin_crm_email_templates_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $form = $this->deleteForm($id);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));

            return $this->redirectToRoute('admin_crm_email_templates_edit', ['id' => $id], status: Response::HTTP_SEE_OTHER);
        }

        try {
            $this->commandBus->dispatch(new DeleteEmailTemplate($id));
        } catch (HandlerFailedException $failure) {
            throw [] !== $failure->getWrappedExceptions(EmailTemplateNotFound::class) ? $this->createNotFoundException() : $failure;
        }
        $this->addFlash('success', CrmFlash::message('email_template.flash.deleted'));

        return $this->redirectToRoute('admin_crm_email_templates', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return bool false when the name is taken; the reason is then on the form's `name` field
     */
    private function save(SaveEmailTemplate $command, FormInterface $form): bool
    {
        try {
            $this->commandBus->dispatch($command);

            return true;
        } catch (HandlerFailedException $failure) {
            if ([] === $failure->getWrappedExceptions(EmailTemplateNameTaken::class)) {
                throw $failure;
            }
            $form->get('name')->addError(new FormError($this->translator->trans('email_template.name_taken', [], 'crm')));

            return false;
        }
    }

    private function savedResponse(): RedirectResponse
    {
        $this->addFlash('success', CrmFlash::message('email_template.flash.saved'));

        return $this->redirectToRoute('admin_crm_email_templates', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function deleteForm(string $id): FormInterface
    {
        return $this->forms->createNamed(self::DELETE_FORM, ConfirmActionType::class, null, [
            'action' => $this->generateUrl('admin_crm_email_templates_delete', ['id' => $id]),
        ]);
    }
}
