<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Command\ChangeInquiryStatus;
use App\Crm\Form\ReturnPath;
use App\Crm\ReadModel\InquiryInboxQuery;
use App\Crm\ValueObject\CrmFlash;
use App\Crm\ValueObject\InquiryStatus;
use App\Inquiries\Command\MarkInquiry;
use App\Inquiries\Exception\InquiryNotFound;
use App\Inquiries\ValueObject\InquiryOverride;
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
 * The staff mark of an inquiry (D19, G7): spam, test, or none (an empty `override`). A POST form with PRG like the
 * status form; the mark it already has sends no command, so it leaves no audit row. Marking a `new` inquiry also files
 * it as `done`, so the «Neu» badge, tab and counts leave it out without reading `inquiries` (D56); an unmark does not
 * bring `new` back. A purged inquiry goes back to the list.
 */
#[IsGranted('ROLE_EDITOR')]
final class InquiryMarkController extends AbstractController
{
    #[Route('/admin/crm/anfragen/{id}/markierung', name: 'admin_crm_inquiries_mark', requirements: ['id' => Requirement::UUID], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $id, Request $request, InquiryInboxQuery $inbox, MessageBusInterface $commandBus): Response
    {
        $input = $request->request->all();
        $list = $this->generateUrl('admin_crm_inquiries');
        $back = ReturnPath::safe($input['return_to'] ?? null, $list);
        $token = $input['_token'] ?? null;
        $override = $input['override'] ?? null;

        if (!\is_string($token) || !$this->isCsrfTokenValid(InquiryStatusController::TOKEN, $token)
            || !\is_string($override) || ('' !== $override && null === InquiryOverride::tryFrom($override))) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));

            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }
        $wanted = '' === $override ? null : InquiryOverride::from($override);

        $detail = $inbox->detail($id);
        if (null === $detail) {
            return $this->gone($list);
        }
        if ($detail->override !== $wanted) {
            try {
                $commandBus->dispatch(new MarkInquiry($id, $wanted));
                if (null !== $wanted && InquiryStatus::New === $detail->status) {
                    $commandBus->dispatch(new ChangeInquiryStatus($id, InquiryStatus::Done));
                }
            } catch (HandlerFailedException $failure) {
                return [] !== $failure->getWrappedExceptions(InquiryNotFound::class) ? $this->gone($list) : throw $failure;
            }
            $this->addFlash('success', CrmFlash::message(null === $wanted ? 'inquiry.flash.unmarked' : 'inquiry.flash.marked'));
        }

        return $this->redirect($back, Response::HTTP_SEE_OTHER);
    }

    private function gone(string $list): Response
    {
        $this->addFlash('error', CrmFlash::message('inquiry.flash.gone'));

        return $this->redirect($list, Response::HTTP_SEE_OTHER);
    }
}
