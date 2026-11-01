<?php

declare(strict_types=1);

namespace App\Crm;

use App\Crm\Controller\DealTierController;
use App\Crm\ReadModel\TierOffer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The offer in the stage endpoint's answer: all the board's won prompt needs for its form (D17-a, D20), so the prompt
 * comes from the server and there is no second endpoint.
 */
final readonly class TierOfferProps
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
    public function of(?TierOffer $offer): ?array
    {
        return null === $offer ? null : [
            'action' => $this->urls->generate('admin_crm_deals_apply_tier', ['id' => $offer->dealId]),
            'token' => $this->tokens->getToken(DealTierController::TOKEN)->getValue(),
            'expected' => $offer->current->value,
            'text' => $this->translator->trans('deal.tier.text', [
                'venue' => $offer->venueName,
                'current' => $offer->current->trans($this->translator),
                'offered' => $offer->offered->trans($this->translator),
            ], 'crm'),
        ];
    }
}
