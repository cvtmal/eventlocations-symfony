<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The pilot cohort of a Location (G11, CMO §6.5), stored in `crm_companies.cohort` (chk_crm_companies_cohort): A a
 * letter with the printed preview, B a letter with the price comparison, C a direct contact from an existing
 * relationship, D a partner's introduction.
 */
enum OutreachCohort: string implements TranslatableInterface
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('outreach.cohort.'.$this->value, [], 'crm', $locale);
    }
}
