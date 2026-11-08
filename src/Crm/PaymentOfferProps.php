<?php

declare(strict_types=1);

namespace App\Crm;

use App\Crm\Controller\DealPaymentController;
use App\Crm\ReadModel\PaymentOffer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The offer in the stage endpoint's answer: all the board's won prompt needs for its form (D17-a, D20), so the prompt
 * comes from the server and there is no second endpoint. The island still calls it `tierOffer`; since G9 it records
 * the payment of the deal's term (no invoice reference from the board: the deal page's dialog has the field).
 */
final readonly class PaymentOfferProps
{
    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private CsrfTokenManagerInterface $tokens,
    ) {
    }

    /**
     * @return ?array{action: string, token: string, expected: string, text: string}
     */
    public function of(?PaymentOffer $offer): ?array
    {
        return null === $offer ? null : [
            'action' => $this->urls->generate('admin_crm_deals_mark_paid', ['id' => $offer->dealId]),
            'token' => $this->tokens->getToken(DealPaymentController::TOKEN)->getValue(),
            'expected' => $offer->expected,
            'text' => $this->translator->trans('deal.payment.text', [
                'venue' => $offer->venueName,
                'tier' => $offer->tier->name,
                'start' => $offer->startsOn,
                'end' => $offer->endsOn,
            ], 'crm'),
        ];
    }
}
