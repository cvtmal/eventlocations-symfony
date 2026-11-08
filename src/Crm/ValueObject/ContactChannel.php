<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How a Location is reached for outreach (G11), stored in `crm_companies.contact_channel`
 * (chk_crm_companies_contact_channel). Only a postal letter carries a campaign code (PO decision).
 */
enum ContactChannel: string implements TranslatableInterface
{
    case Postal = 'postal';
    case Email = 'email';
    case Phone = 'phone';
    case Referral = 'referral';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('outreach.channel.'.$this->value, [], 'crm', $locale);
    }
}
