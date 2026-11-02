<?php

declare(strict_types=1);

namespace App\Crm\Controller\Api;

use App\Admin\Security\AdminIdentity;
use App\Crm\Command\SendCrmEmail;
use App\Crm\CrmApi;
use App\Crm\ReadModel\EmailMergeQuery;
use App\Crm\ReadModel\EmailTemplateQuery;
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
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sending from a record (CRM-10): 201 `{"entry"}` for a mail that left and for one that failed, because a failure is an
 * outcome staff read from the entry's `email.status`, with their text still in the composer. The draft fills a template
 * for the composer at pick time and writes nothing, so a typed token stays literal and what is on screen is what goes out.
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['POST'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class EmailsController extends AbstractController
{
    public function __construct(
        private readonly CrmApi $api,
        private readonly TimelineApi $timeline,
        private readonly MessageBusInterface $commandBus,
        private readonly EmailTemplateQuery $templates,
        private readonly EmailMergeQuery $merge,
    ) {
    }

    #[Route('/admin/api/crm/emails', name: 'admin_api_crm_email_send', methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function send(Request $request, #[CurrentUser] AdminIdentity $user): JsonResponse
    {
        $input = $this->api->newEmail($request);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        $id = EntityId::generate();
        try {
            $this->commandBus->dispatch(new SendCrmEmail($id, $input->kind, $input->recordId, $input->contactId, $input->cc, $input->subject, $input->body, $user->id()));
        } catch (HandlerFailedException $failure) {
            return $this->api->failure($failure);
        }

        return $this->timeline->stored($id, $user->id(), Response::HTTP_CREATED);
    }

    #[Route('/admin/api/crm/email-draft', name: 'admin_api_crm_email_draft', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function draft(Request $request): JsonResponse
    {
        $input = $this->api->emailDraft($request);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        $template = $this->templates->find($input->templateId);
        $fields = null === $template ? null : $this->merge->fields($input->kind, $input->recordId, $input->contactId);
        if (null === $template || null === $fields) {
            return $this->api->notFound();
        }

        return new JsonResponse(['subject' => $fields->apply($template->subject), 'body' => $fields->apply($template->bodyMarkdown)]);
    }
}
