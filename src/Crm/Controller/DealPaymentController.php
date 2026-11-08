<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Command\MarkDealPaid;
use App\Crm\Exception\DealNotFound;
use App\Crm\Exception\DealNotPayable;
use App\Crm\Exception\StaleProperty;
use App\Crm\Form\ReturnPath;
use App\Crm\ValueObject\CrmFlash;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Entity\Subscription;
use App\Venues\Exception\SubscriptionRefused;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * «Zahlung erfassen» (G9, D20), one plain form with PRG: the record's menu posts it, and so does the board's won
 * prompt. `expected` is the venue's paid-until day the prompt showed ('' when it never paid), `invoice_ref` the
 * optional invoice number (the board sends none), `return_to` goes through `ReturnPath` (P11). A refused command
 * rolled back, so it leaves no audit row.
 */
#[IsGranted('ROLE_EDITOR')]
final class DealPaymentController extends AbstractController
{
    /** The token id of the form, a plain one because the board builds it from the stage answer. */
    public const string TOKEN = 'submit';

    private const string DAY_SHAPE = '/\A(\d{4}-\d{2}-\d{2})?\z/';

    #[Route('/admin/crm/deals/{id}/zahlung', name: 'admin_crm_deals_mark_paid', requirements: ['id' => Requirement::UUID], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $id, Request $request, MessageBusInterface $commandBus): Response
    {
        $input = $request->request->all();
        $back = ReturnPath::safe($input['return_to'] ?? null, $this->generateUrl('admin_crm_deals_show', ['id' => $id]));
        $token = $input['_token'] ?? null;
        $expected = \is_string($input['expected'] ?? null) && 1 === preg_match(self::DAY_SHAPE, $input['expected']) ? $input['expected'] : null;
        $invoiceRef = \is_string($input['invoice_ref'] ?? null) ? trim($input['invoice_ref']) : '';
        $invoiceRefValid = mb_check_encoding($invoiceRef, 'UTF-8') && !str_contains($invoiceRef, "\0") && mb_strlen($invoiceRef) <= Subscription::INVOICE_REF_MAX;
        if (!\is_string($token) || !$this->isCsrfTokenValid(self::TOKEN, $token) || null === $expected || !$invoiceRefValid) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));

            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }

        try {
            $commandBus->dispatch(new MarkDealPaid($id, $expected, '' === $invoiceRef ? null : $invoiceRef));
            $this->addFlash('success', CrmFlash::message('deal.payment.flash.recorded'));
        } catch (HandlerFailedException $failure) {
            $this->addFlash('error', CrmFlash::message(match (true) {
                [] !== $failure->getWrappedExceptions(StaleProperty::class) => 'deal.payment.flash.stale',
                [] !== $failure->getWrappedExceptions(DealNotPayable::class) => 'deal.payment.flash.gone',
                [] !== $failure->getWrappedExceptions(SubscriptionRefused::class, true) => self::refusal($failure),
                [] !== $failure->getWrappedExceptions(DealNotFound::class) => throw $this->createNotFoundException(),
                default => throw $failure,
            }));
        }

        return $this->redirect($back, Response::HTTP_SEE_OTHER);
    }

    private static function refusal(HandlerFailedException $failure): string
    {
        $refused = array_values($failure->getWrappedExceptions(SubscriptionRefused::class, true))[0] ?? null;

        return $refused instanceof SubscriptionRefused && SubscriptionRefused::PAST === $refused->reason ? 'deal.payment.flash.past' : 'deal.payment.flash.overlap';
    }
}
