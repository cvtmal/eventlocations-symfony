<?php

declare(strict_types=1);

namespace App\Crm\Controller\Api;

use App\Admin\Security\AdminIdentity;
use App\Crm\Command\CreateTask;
use App\Crm\CrmApi;
use App\Shared\Translation\LocalizedRoutes;
use App\Shared\Uid\EntityId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The composer's Aufgabe tab (D20): 201 `{"id"}`; the island then reloads the page, because the Aufgaben card is
 * server-rendered. Errors land under the inputs (`title`, `dueOn`, `assigneeId`), a missing record is a 404.
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['POST'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class TasksController extends AbstractController
{
    #[Route('/admin/api/crm/tasks', name: 'admin_api_crm_tasks', methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, CrmApi $api, MessageBusInterface $commandBus, #[CurrentUser] AdminIdentity $user): JsonResponse
    {
        $input = $api->newTask($request);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        $id = EntityId::generate();
        try {
            $commandBus->dispatch(new CreateTask($id, $input->title, $input->dueOn, $input->assigneeId, $user->id(), $input->kind, $input->recordId));
        } catch (HandlerFailedException $failure) {
            return $api->taskFailure($failure);
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }
}
