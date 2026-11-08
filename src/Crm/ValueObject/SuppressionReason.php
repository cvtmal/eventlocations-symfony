<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Why a Location or an address is on the suppression list (G11), stored in `suppressions.reason`
 * (chk_suppressions_reason): the opt-out link of a mail, or an objection staff took down (a call, a letter, a reply).
 */
enum SuppressionReason: string implements TranslatableInterface
{
    case OptOut = 'opt_out';
    case Objection = 'objection';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('suppression.reason.'.$this->value, [], 'crm', $locale);
    }
}
