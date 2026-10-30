<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Command\ChangeAdminRole;
use App\Admin\Command\CreateAdminUser;
use App\Admin\Command\DeactivateAdminUser;
use App\Admin\Command\ReactivateAdminUser;
use App\Admin\Command\ResetAdminPassword;
use App\Admin\Command\ResetAdminTotp;
use App\Admin\Exception\AdminUserRuleViolation;
use App\Admin\Form\ChangeAdminRoleData;
use App\Admin\Form\ChangeAdminRoleType;
use App\Admin\Form\ConfirmActionType;
use App\Admin\Form\CreateAdminUserData;
use App\Admin\Form\CreateAdminUserType;
use App\Admin\Form\ResetAdminPasswordData;
use App\Admin\Form\ResetAdminPasswordType;
use App\Admin\ReadModel\AdminUserListQuery;
use App\Admin\ReadModel\AdminUserRow;
use App\Admin\Security\AdminIdentity;
use App\Admin\ValueObject\AdminRole;
use App\Shared\Translation\LocalizedRoutes;
use App\Shared\Uid\EntityId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Staff accounts (ADM-1, ADM-2): admins only. Writes go through `command.bus`, so each leaves an audit row.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/benutzer', options: [LocalizedRoutes::UNLOCALIZED => true])]
final class UsersController extends AbstractController
{
    private const array ACTION_FORMS = [
        'deactivate' => 'admin_users_deactivate',
        'reactivate' => 'admin_users_reactivate',
        'reset_totp' => 'admin_users_reset_totp',
    ];

    public function __construct(
        private readonly AdminUserListQuery $users,
        private readonly MessageBusInterface $commandBus,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'admin_users', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/users/index.html.twig', ['users' => $this->users->all()]);
    }

    #[Route('/neu', name: 'admin_users_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new CreateAdminUserData();
        $form = $this->createForm(CreateAdminUserType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $data->email && null !== $data->displayName && null !== $data->role && null !== $data->plainPassword);
            $id = EntityId::generate();

            if ($this->tryDispatch(new CreateAdminUser($id, $data->email, $data->displayName, $data->role, $data->plainPassword), $form)) {
                $this->addFlash('success', 'flash.user_created');

                return $this->redirectToRoute('admin_users_edit', ['id' => $id]);
            }
        }

        return $this->render('admin/users/new.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}', name: 'admin_users_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, #[CurrentUser] AdminIdentity $identity): Response
    {
        $user = $this->user($id);
        $data = new ChangeAdminRoleData($user->role);
        $form = $this->createForm(ChangeAdminRoleType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            \assert($data->role instanceof AdminRole);

            if ($this->tryDispatch(new ChangeAdminRole($id, $data->role, $identity->id()), $form)) {
                $this->addFlash('success', 'flash.role_changed');

                return $this->redirectToRoute('admin_users_edit', ['id' => $id]);
            }
        }

        return $this->renderEdit($user, $form, $this->passwordForm($id), $form->isSubmitted());
    }

    #[Route('/{id}/passwort', name: 'admin_users_password', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function password(string $id, Request $request): Response
    {
        $user = $this->user($id);
        $form = $this->passwordForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            \assert($data instanceof ResetAdminPasswordData && null !== $data->plainPassword);

            if ($this->tryDispatch(new ResetAdminPassword($id, $data->plainPassword), $form)) {
                $this->addFlash('success', 'flash.password_changed');

                return $this->redirectToRoute('admin_users_edit', ['id' => $id]);
            }
        }

        return $this->renderEdit($user, $this->createForm(ChangeAdminRoleType::class, new ChangeAdminRoleData($user->role)), $form, true);
    }

    #[Route('/{id}/deaktivieren', name: 'admin_users_deactivate', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function deactivate(string $id, Request $request, #[CurrentUser] AdminIdentity $identity): Response
    {
        return $this->act($id, $request, 'deactivate', new DeactivateAdminUser($id, $identity->id()), 'flash.user_deactivated');
    }

    #[Route('/{id}/reaktivieren', name: 'admin_users_reactivate', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function reactivate(string $id, Request $request): Response
    {
        return $this->act($id, $request, 'reactivate', new ReactivateAdminUser($id), 'flash.user_reactivated');
    }

    #[Route('/{id}/2fa-zuruecksetzen', name: 'admin_users_reset_totp', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function resetTotp(string $id, Request $request): Response
    {
        return $this->act($id, $request, 'reset_totp', new ResetAdminTotp($id), 'flash.totp_reset');
    }

    private function act(string $id, Request $request, string $formName, object $command, string $successFlash): Response
    {
        $this->user($id);
        $form = $this->actionForm($formName, $id);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'flash.action_failed');
        } elseif ($this->tryDispatch($command, $form)) {
            $this->addFlash('success', $successFlash);
        } else {
            foreach ($form->getErrors() as $error) {
                \assert($error instanceof FormError);
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('admin_users_edit', ['id' => $id]);
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return bool false when a business rule refused the command; the reason is then on the form
     */
    private function tryDispatch(object $command, FormInterface $form): bool
    {
        try {
            $this->commandBus->dispatch($command);

            return true;
        } catch (HandlerFailedException $failure) {
            $violation = AdminUserRuleViolation::unwrap($failure);
            $target = null !== $violation->field && $form->has($violation->field) ? $form->get($violation->field) : $form;
            $target->addError(new FormError($this->translator->trans($violation->translationKey(), [], 'admin')));

            return false;
        }
    }

    private function user(string $id): AdminUserRow
    {
        return $this->users->find($id) ?? throw $this->createNotFoundException();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function passwordForm(string $id): FormInterface
    {
        return $this->createForm(ResetAdminPasswordType::class, new ResetAdminPasswordData(), [
            'action' => $this->generateUrl('admin_users_password', ['id' => $id]),
        ]);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function actionForm(string $name, string $id): FormInterface
    {
        return $this->forms->createNamed($name, ConfirmActionType::class, null, [
            'action' => $this->generateUrl(self::ACTION_FORMS[$name], ['id' => $id]),
        ]);
    }

    /**
     * @param FormInterface<mixed> $roleForm
     * @param FormInterface<mixed> $passwordForm
     */
    private function renderEdit(AdminUserRow $user, FormInterface $roleForm, FormInterface $passwordForm, bool $failed): Response
    {
        return $this->render('admin/users/edit.html.twig', [
            'user' => $user,
            'role_form' => $roleForm,
            'password_form' => $passwordForm,
            'deactivate_form' => $this->actionForm('deactivate', $user->id),
            'reactivate_form' => $this->actionForm('reactivate', $user->id),
            'reset_totp_form' => $this->actionForm('reset_totp', $user->id),
        ], new Response(status: $failed ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
