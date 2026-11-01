<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Why a deal was lost (N9), stored in `crm_deals.lost_reason` (chk_crm_deals_lost_reason). A CHECK cannot read the
 * stage rows, so the handlers of `MoveDealStage` and `ChangeDealLostReason` are what require one for a lost stage.
 */
enum LostReason: string implements TranslatableInterface
{
    case Budget = 'budget';
    case Competitor = 'competitor';
    case NoNeed = 'no_need';
    case NoResponse = 'no_response';
    case Other = 'other';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('deal.lost_reason.'.$this->value, [], 'crm', $locale);
    }
}
