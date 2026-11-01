<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Form\ReturnPath;
use App\Crm\ReadModel\InquiryInboxQuery;
use App\Crm\ValueObject\CrmFlash;
use App\Inquiries\Command\DeliverInquiry;
use App\Inquiries\ValueObject\DeliveryOutcome;
use App\Inquiries\ValueObject\MailStatus;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Delivers an inquiry whose venue mail did not go out (N3): the cure for what `app:inquiries:report-stuck` prints. The
 * handler claims the row itself, so a delivery in progress is waited for, and a `sent` found here sends nothing.
 * Not audited: `DeliverInquiry` belongs to Inquiries and records its outcome on the row.
 */
#[IsGranted('ROLE_EDITOR')]
final class InquiryResendController extends AbstractController
{
    private const array UNDELIVERED = [MailStatus::Failed, MailStatus::Pending];

    #[Route('/admin/crm/anfragen/{id}/senden', name: 'admin_crm_inquiries_resend', requirements: ['id' => Requirement::UUID], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $id, Request $request, InquiryInboxQuery $inbox, MessageBusInterface $commandBus): Response
    {
        $input = $request->request->all();
        $list = $this->generateUrl('admin_crm_inquiries');
        $back = ReturnPath::safe($input['return_to'] ?? null, $list);
        $token = $input['_token'] ?? null;

        if (!\is_string($token) || !$this->isCsrfTokenValid(InquiryStatusController::TOKEN, $token)) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));

            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }

        $inquiry = $inbox->detail($id);
        if (null === $inquiry) {
            $this->addFlash('error', CrmFlash::message('inquiry.flash.gone'));

            return $this->redirect($list, Response::HTTP_SEE_OTHER);
        }

        if (!\in_array($inquiry->venueMail, self::UNDELIVERED, true)) {
            $this->addFlash('success', CrmFlash::message('inquiry.flash.already_sent'));

            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }

        $outcome = $commandBus->dispatch(new DeliverInquiry($id))->last(HandledStamp::class)?->getResult();
        \assert($outcome instanceof DeliveryOutcome);

        if ($outcome->venueMailSent) {
            $this->addFlash('success', CrmFlash::message('inquiry.flash.resent'));
        } else {
            $this->addFlash('error', CrmFlash::message('inquiry.flash.resend_failed'));
        }

        return $this->redirect($back, Response::HTTP_SEE_OTHER);
    }
}
