<?php

declare(strict_types=1);

namespace App\Crm\Controller\Api;

use App\Admin\Extension\TimelineActivityTypeRegistry;
use App\Admin\Security\AdminIdentity;
use App\Crm\Command\DeleteActivity;
use App\Crm\Command\EditActivity;
use App\Crm\Command\LogActivity;
use App\Crm\CrmApi;
use App\Crm\ReadModel\TimelineQuery;
use App\Crm\TimelineApi;
use App\Shared\Translation\LocalizedRoutes;
use App\Shared\Uid\EntityId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The timeline's writes (CRM-4, D14): a new entry answers 201 `{"entry"}`, an edit 200 `{"entry"}`, a delete 204.
 * An edit that changes nothing answers without a dispatch, so it leaves no audit row (P1). A foreign or read-only
 * entry is 403, for `admin` too: the handlers decide it, so the no-op check asks `canBeEditedBy()` first and a
 * foreign edit with the same text is refused like any other.
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'PATCH', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class ActivitiesController extends AbstractController
{
    public function __construct(
        private readonly CrmApi $api,
        private readonly TimelineApi $timeline,
        private readonly TimelineQuery $entries,
        private readonly TimelineActivityTypeRegistry $types,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/admin/api/crm/activities', name: 'admin_api_crm_activity_log', methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function log(Request $request, #[CurrentUser] AdminIdentity $user): JsonResponse
    {
        $input = $this->api->newActivity($request, $this->types->composableCodes());
        if ($input instanceof JsonResponse) {
            return $input;
        }

        $id = EntityId::generate();
        try {
            $this->commandBus->dispatch(new LogActivity($id, $input->kind, $input->recordId, $input->type, $input->body, $input->occurredAt, $user->id()));
        } catch (HandlerFailedException $failure) {
            return $this->api->failure($failure);
        }

        return $this->timeline->stored($id, $user->id(), Response::HTTP_CREATED);
    }

    #[Route('/admin/api/crm/activities/{id}', name: 'admin_api_crm_activity_edit', requirements: ['id' => Requirement::UUID], methods: ['PATCH'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function edit(string $id, Request $request, #[CurrentUser] AdminIdentity $user): JsonResponse
    {
        $input = $this->api->activityEdit($request);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        $entry = $this->entries->entry($id);
        if (null === $entry) {
            return $this->api->notFound();
        }
        if ($entry->says($input->body, $input->occurredAt) && $entry->canBeEditedBy($user->id(), $this->types->composableCodes())) {
            return $this->timeline->answer($entry, $user->id());
        }

        try {
            $this->commandBus->dispatch(new EditActivity($id, $input->body, $input->occurredAt, $user->id()));
        } catch (HandlerFailedException $failure) {
            return $this->api->failure($failure);
        }

        return $this->timeline->stored($id, $user->id());
    }

    #[Route('/admin/api/crm/activities/{id}', name: 'admin_api_crm_activity_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function delete(string $id, #[CurrentUser] AdminIdentity $user): Response
    {
        try {
            $this->commandBus->dispatch(new DeleteActivity($id, $user->id()));
        } catch (HandlerFailedException $failure) {
            return $this->api->failure($failure);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
