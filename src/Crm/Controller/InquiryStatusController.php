<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Command\ChangeInquiryStatus;
use App\Crm\Exception\InquiryNotFound;
use App\Crm\Form\ReturnPath;
use App\Crm\ReadModel\InquiryInboxQuery;
use App\Crm\ValueObject\CrmFlash;
use App\Crm\ValueObject\InquiryStatus;
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
 * The status of an inquiry (CRM-9): a POST form with PRG, like the task toggle. The form states the wanted status, and
 * the status it already has sends no command, so it leaves no audit row (P1). A purged inquiry goes back to the list,
 * as its own page would answer 404, and so does anything without a usable `return_to`.
 */
#[IsGranted('ROLE_EDITOR')]
final class InquiryStatusController extends AbstractController
{
    /** The token id of the detail pane's forms, plain forms like the task toggle's. */
    public const string TOKEN = 'submit';

    #[Route('/admin/crm/anfragen/{id}/status', name: 'admin_crm_inquiries_status', requirements: ['id' => Requirement::UUID], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $id, Request $request, InquiryInboxQuery $inbox, MessageBusInterface $commandBus): Response
    {
        $input = $request->request->all();
        $list = $this->generateUrl('admin_crm_inquiries');
        $back = ReturnPath::safe($input['return_to'] ?? null, $list);
        $token = $input['_token'] ?? null;
        $wanted = \is_string($input['status'] ?? null) ? InquiryStatus::tryFrom($input['status']) : null;

        if (!\is_string($token) || !$this->isCsrfTokenValid(self::TOKEN, $token) || null === $wanted) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));

            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }

        $current = $inbox->status($id);
        if (null === $current) {
            return $this->gone($list);
        }
        if ($current !== $wanted) {
            try {
                $commandBus->dispatch(new ChangeInquiryStatus($id, $wanted));
                $this->addFlash('success', CrmFlash::message('inquiry.flash.status_changed'));
            } catch (HandlerFailedException $failure) {
                return [] !== $failure->getWrappedExceptions(InquiryNotFound::class) ? $this->gone($list) : throw $failure;
            }
        }

        return $this->redirect($back, Response::HTTP_SEE_OTHER);
    }

    private function gone(string $list): Response
    {
        $this->addFlash('error', CrmFlash::message('inquiry.flash.gone'));

        return $this->redirect($list, Response::HTTP_SEE_OTHER);
    }
}
