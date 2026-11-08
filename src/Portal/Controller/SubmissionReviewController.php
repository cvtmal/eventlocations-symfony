<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Admin\Security\AdminIdentity;
use App\Cms\ValueObject\VenueNumber;
use App\Portal\Command\AcceptSubmission;
use App\Portal\Command\RejectSubmission;
use App\Portal\Exception\SubmissionNotOpen;
use App\Portal\Form\RejectSubmissionData;
use App\Portal\Form\RejectSubmissionType;
use App\Portal\Mailer\PortalMailer;
use App\Portal\ReadModel\PendingSubmissionQuery;
use App\Portal\ReadModel\PendingSubmissionRow;
use App\Portal\ValueObject\PortalFlash;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Event\VenueEvents;
use App\Venues\Exception\VenueRuleViolation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Staff decide on a new location (POR-13), as `ReviewController` does on an access request: a decision reads the open
 * row first, and the mail comes after the dispatch, so it cannot undo the decision. A row that is gone redirects
 * instead of a 404: a double click or a publish in the CMS empties it, and the queue is the answer to both. An accept
 * that was the venue's first publish fires `NewVenuePublished` (Q20); one the CMS already published fired it there.
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/pruefung/neu/{number}', requirements: ['number' => VenueNumber::REQUIREMENT], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class SubmissionReviewController extends AbstractController
{
    private const string TOKEN = 'submit';

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly PendingSubmissionQuery $queue,
        private readonly PortalMailer $mailer,
        private readonly VenueEvents $events,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/annehmen', name: 'admin_portal_review_submission_accept', methods: ['POST'])]
    public function accept(int $number, Request $request, #[CurrentUser] AdminIdentity $staff): Response
    {
        $token = $request->request->all()['_token'] ?? null;
        if (!\is_string($token) || !$this->isCsrfTokenValid(self::TOKEN, $token)) {
            return $this->back('error', 'review.flash.action_failed');
        }

        $row = $this->queue->find($number);
        if (null === $row) {
            return $this->back('error', 'review.flash.gone');
        }

        try {
            $first = $this->commandBus->dispatch(new AcceptSubmission($number, $staff->id()))->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failure) {
            if ([] !== $failure->getWrappedExceptions(SubmissionNotOpen::class)) {
                return $this->back('error', 'review.flash.gone');
            }

            return $this->back('error', 'onboarding.queue.flash.missing', ['missing' => $this->missing(VenueRuleViolation::unwrap($failure))]);
        }

        // In `finally` so that an unexpected mail error cannot lose the event.
        try {
            $mailed = null === $row->managerEmail || $this->mailer->sendLocationAccepted($row->managerEmail, (string) $row->managerFirstName, $row->name, $number, (string) $row->slug);
        } finally {
            if (true === $first) {
                $this->events->newVenuePublished($row->venueId);
            }
        }

        return $mailed ? $this->back('success', 'onboarding.queue.flash.accepted') : $this->back('error', 'onboarding.queue.flash.accepted_mail_failed');
    }

    #[Route('/ablehnen', name: 'admin_portal_review_submission_reject', methods: ['GET', 'POST'])]
    public function reject(int $number, Request $request, #[CurrentUser] AdminIdentity $staff): Response
    {
        $row = $this->queue->find($number);
        if (null === $row) {
            return $this->back('error', 'review.flash.gone');
        }

        $form = $this->createForm(RejectSubmissionType::class, new RejectSubmissionData());
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            return $this->renderReject($row, $form);
        }
        if (!$form->isValid()) {
            return $this->renderReject($row, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $form->getData();
        \assert($data instanceof RejectSubmissionData);
        $reason = (string) $data->reason;
        $this->commandBus->dispatch(new RejectSubmission($number, $staff->id(), $reason));

        $mailed = null === $row->managerEmail || $this->mailer->sendLocationRejected($row->managerEmail, (string) $row->managerFirstName, $row->name, $number, $reason);

        return $mailed ? $this->back('success', 'onboarding.queue.flash.rejected') : $this->back('error', 'onboarding.queue.flash.rejected_mail_failed');
    }

    private function missing(VenueRuleViolation $violation): string
    {
        return implode(', ', array_map(fn (string $code): string => $this->translator->trans('editor.checklist.item.'.$code, [], 'cms'), $violation->missing));
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderReject(PendingSubmissionRow $row, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render('admin/portal/review/reject.html.twig', ['row' => $row, 'form' => $form], new Response(status: $status));
    }

    /**
     * @param array<string, string> $parameters
     */
    private function back(string $type, string $key, array $parameters = []): Response
    {
        $this->addFlash($type, PortalFlash::message($key, $parameters));

        return $this->redirectToRoute('admin_portal_review', ['tab' => 'neu'], Response::HTTP_SEE_OTHER);
    }
}
