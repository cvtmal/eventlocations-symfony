<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A company's lifecycle (S13), stored in `crm_companies.stage` (chk_crm_companies_stage). Set by
 * hand in #27; #28 derives it from the deals and writes the same column, so no reader changes.
 */
enum CompanyStage: string implements TranslatableInterface
{
    case Prospect = 'prospect';
    case Customer = 'customer';
    case FormerCustomer = 'former_customer';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('company.stage.'.$this->value, [], 'crm', $locale);
    }
}
