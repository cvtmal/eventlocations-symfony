<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Why we may contact a Location (D20, UWG Art. 3 lit. o), stored in `crm_companies.contact_basis`
 * (chk_crm_companies_contact_basis): its consent, an existing customer relationship, a single business contact, or none
 * (letters only). Staff record it; it refuses nothing by itself, the suppression list does.
 */
enum ContactBasis: string implements TranslatableInterface
{
    case Consent = 'consent';
    case Customer = 'customer';
    case OneToOne = 'one_to_one';
    case None = 'none';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('outreach.basis.'.$this->value, [], 'crm', $locale);
    }
}
