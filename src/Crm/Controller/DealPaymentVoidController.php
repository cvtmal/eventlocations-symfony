<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\ReadModel\DealPaymentOfferQuery;
use App\Crm\ValueObject\CrmFlash;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\VoidSubscription;
use App\Venues\Exception\SubscriptionNotFound;
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
 * «Zahlung stornieren» (G9 finish, D42), one plain form with PRG from the deal record's menu: voids the venue's latest
 * term while it has not ended, so a payment recorded by mistake does not leave the venue on the paid tier for a year.
 * `subscription` is the term the dialog showed; the deal's live `VoidOffer` must still name it, else the screen was
 * stale. The venue's audit row (`VoidSubscription`) keeps what was voided.
 */
#[IsGranted('ROLE_EDITOR')]
final class DealPaymentVoidController extends AbstractController
{
    #[Route('/admin/crm/deals/{id}/zahlung-stornieren', name: 'admin_crm_deals_void_payment', requirements: ['id' => Requirement::UUID], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $id, Request $request, DealPaymentOfferQuery $payments, MessageBusInterface $commandBus): Response
    {
        $back = $this->generateUrl('admin_crm_deals_show', ['id' => $id]);
        $token = $request->request->get('_token');
        $subscription = $request->request->get('subscription');
        if (!\is_string($token) || !$this->isCsrfTokenValid(DealPaymentController::TOKEN, $token) || !\is_string($subscription)) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));

            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }
        $offer = $payments->forDeal($id)->void;
        if (null === $offer || $offer->subscriptionId !== $subscription) {
            $this->addFlash('error', CrmFlash::message('deal.void.flash.stale'));

            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }

        try {
            $commandBus->dispatch(new VoidSubscription($offer->venueNumber, $offer->subscriptionId, $offer->tier->code, $offer->startsOn, $offer->endsOn, $offer->amountRappen));
            $this->addFlash('success', CrmFlash::message('deal.void.flash.voided'));
        } catch (HandlerFailedException $failure) {
            if ([] === $failure->getWrappedExceptions(SubscriptionNotFound::class, true) && [] === $failure->getWrappedExceptions(SubscriptionRefused::class, true)) {
                throw $failure;
            }
            $this->addFlash('error', CrmFlash::message('deal.void.flash.stale'));
        }

        return $this->redirect($back, Response::HTTP_SEE_OTHER);
    }
}
