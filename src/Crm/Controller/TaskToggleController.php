<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Command\CompleteTask;
use App\Crm\Command\ReopenTask;
use App\Crm\Exception\TaskNotFound;
use App\Crm\Form\ReturnPath;
use App\Crm\ReadModel\TaskListQuery;
use App\Crm\ValueObject\CrmFlash;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Done and undone (CRM-5, D20): a POST form with PRG, wherever a task is shown. The form states the wanted state, so a
 * second tab or a double click cannot flip it back, and a task already there sends no command, so it leaves no audit
 * row (P1). `return_to` goes through `ReturnPath::safe()` (P11).
 */
#[IsGranted('ROLE_EDITOR')]
final class TaskToggleController extends AbstractController
{
    /** The token id of the toggle forms, plain forms because a page holds many. */
    public const string TOKEN = 'submit';

    #[Route('/admin/crm/aufgaben/{id}/status', name: 'admin_crm_tasks_toggle', requirements: ['id' => Requirement::UUID], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $id, Request $request, TaskListQuery $tasks, MessageBusInterface $commandBus): Response
    {
        $input = $request->request->all();
        $back = ReturnPath::safe($input['return_to'] ?? null, $this->generateUrl('admin_crm_tasks'));
        $token = $input['_token'] ?? null;
        $wanted = $input['done'] ?? null;

        if (!\is_string($token) || !$this->isCsrfTokenValid(self::TOKEN, $token) || !\in_array($wanted, ['0', '1'], true)) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));

            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }

        $done = '1' === $wanted;
        $current = $tasks->isDone($id);
        if (null === $current) {
            $this->addFlash('error', CrmFlash::message('task.flash.gone'));
        } elseif ($current !== $done) {
            try {
                $commandBus->dispatch($done ? new CompleteTask($id) : new ReopenTask($id));
                $this->addFlash('success', CrmFlash::message($done ? 'task.flash.done' : 'task.flash.reopened'));
            } catch (HandlerFailedException $failure) {
                [] !== $failure->getWrappedExceptions(TaskNotFound::class) ? $this->addFlash('error', CrmFlash::message('task.flash.gone')) : throw $failure;
            }
        }

        return $this->redirect($back, Response::HTTP_SEE_OTHER);
    }
}
