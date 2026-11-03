<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Admin\Security\AdminIdentity;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Form\ListQueryString;
use App\Crm\Form\TaskData;
use App\Crm\Form\TaskType;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ReadModel\TaskListQuery;
use App\Crm\ReadModel\TaskView;
use App\Crm\ValueObject\CrmFlash;
use App\Crm\ValueObject\TaskDueDate;
use App\Shared\Translation\LocalizedRoutes;
use App\Shared\Uid\EntityId;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The task list (CRM-5, D20): the tabs Alle, Meine, Heute, Überfällig, and "Aufgabe erfassen", which starts due today
 * and assigned to the viewer. An unknown `view` is the default tab. Three statements: the staff, COUNT, the rows.
 */
#[IsGranted('ROLE_EDITOR')]
final class TaskListController extends AbstractController
{
    /** The handler's field names, as `InvalidPropertyValue::$field`, to the form's. */
    private const array FIELDS = ['title' => 'title', 'owner' => 'assignee'];

    #[Route('/admin/crm/aufgaben', name: 'admin_crm_tasks', methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(
        Request $request,
        TaskListQuery $tasks,
        OwnerOptionsQuery $owners,
        MessageBusInterface $commandBus,
        TranslatorInterface $translator,
        ClockInterface $clock,
        #[CurrentUser] AdminIdentity $user,
    ): Response {
        $view = TaskView::tryFrom(ListQueryString::value($request, 'view')) ?? TaskView::DEFAULT;

        $data = new TaskData();
        $data->dueOn = TaskDueDate::today($clock->now());
        $data->assignee = $user->id();
        $form = $this->createForm(TaskType::class, $data, [
            'owners' => OwnerOptionsQuery::selectable($owners->all()),
            'action' => $this->generateUrl('admin_crm_tasks', ['view' => $view->value]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $commandBus->dispatch($data->toCommand(EntityId::generate(), $user->id()));
                $this->addFlash('success', CrmFlash::message('task.flash.created'));

                return $this->redirectToRoute('admin_crm_tasks', ['view' => $view->value], Response::HTTP_SEE_OTHER);
            } catch (HandlerFailedException $failure) {
                $invalid = array_values($failure->getWrappedExceptions(InvalidPropertyValue::class))[0] ?? throw $failure;
                $form->get(self::FIELDS[$invalid->field] ?? throw $failure)->addError(new FormError(
                    $translator->trans($invalid->isRequired() ? 'api.required' : 'api.unknown_choice', [], 'crm'),
                ));
            }
        }

        return $this->render('admin/crm/task/index.html.twig', [
            'form' => $form,
            'view' => $view,
            'views' => TaskView::cases(),
            'list' => $tasks->page($view, $user->id(), ListQueryString::page($request)),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
