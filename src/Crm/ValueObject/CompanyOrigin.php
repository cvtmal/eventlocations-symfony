<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How a company came to be (`crm_companies.origin`, chk_crm_companies_origin): typed in by staff,
 * or created from a venue by the bootstrap or "Firma anlegen" (the design's "Erstellt · aus Location").
 */
enum CompanyOrigin: string implements TranslatableInterface
{
    case Manual = 'manual';
    case Venue = 'venue';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('company.origin.'.$this->value, [], 'crm', $locale);
    }
}
