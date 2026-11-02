<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Admin\Form\ConfirmActionType;
use App\Portal\Command\DeactivatePortalUser;
use App\Portal\Command\ReactivatePortalUser;
use App\Portal\Exception\PortalUserNotFound;
use App\Portal\Form\PortalAccountFilterData;
use App\Portal\Form\PortalAccountFilterType;
use App\Portal\ReadModel\PortalAccountListQuery;
use App\Portal\ValueObject\PortalFlash;
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
 * The manager accounts in the back office (POR-3): admins only. The list's rows post field-less forms, so each
 * action leaves an audit row through `command.bus`, and a deactivated account's sessions end on the next request.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/konten', options: [LocalizedRoutes::UNLOCALIZED => true])]
final class AccountsController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly FormFactoryInterface $forms,
    ) {
    }

    #[Route('', name: 'admin_portal_accounts', methods: ['GET'])]
    public function index(Request $request, PortalAccountListQuery $accounts): Response
    {
        $data = new PortalAccountFilterData();
        // An empty name keeps the parameter flat: ?q=…
        $form = $this->forms->createNamed('', PortalAccountFilterType::class, $data);
        $form->handleRequest($request);
        $valid = !$form->isSubmitted() || $form->isValid();
        $q = $form->isSubmitted() && $valid ? $data->q : null;

        return $this->render('admin/portal/accounts.html.twig', [
            'invalid' => !$valid,
            'q' => $q,
            'list' => $accounts->search($q, self::page($request)),
        ], new Response(status: $valid ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/{id}/deaktivieren', name: 'admin_portal_accounts_deactivate', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function deactivate(string $id, Request $request): Response
    {
        return $this->act($request, 'deactivate', new DeactivatePortalUser($id), 'flash.account_deactivated');
    }

    #[Route('/{id}/reaktivieren', name: 'admin_portal_accounts_reactivate', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function reactivate(string $id, Request $request): Response
    {
        return $this->act($request, 'reactivate', new ReactivatePortalUser($id), 'flash.account_reactivated');
    }

    private function act(Request $request, string $formName, object $command, string $successFlash): Response
    {
        $form = $this->forms->createNamed($formName, ConfirmActionType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commandBus->dispatch($command);
            } catch (HandlerFailedException $failure) {
                throw [] !== $failure->getWrappedExceptions(PortalUserNotFound::class) ? $this->createNotFoundException() : $failure;
            }

            $this->addFlash('success', PortalFlash::message($successFlash));
        } else {
            $this->addFlash('error', PortalFlash::message('flash.account_action_failed'));
        }

        return $this->redirectToRoute('admin_portal_accounts');
    }

    /**
     * `ListQueryString::page()`'s three lines: Portal may not depend on Crm. A page outside the range is clamped
     * by the query.
     */
    private static function page(Request $request): int
    {
        $value = $request->query->all()['page'] ?? null;
        $page = \is_string($value) ? filter_var($value, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        return false === $page ? 1 : $page;
    }
}
