<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a contact is to us (S13), stored in `crm_contacts.kind` (chk_crm_contacts_kind). All three
 * values exist from #27, so #29's couples need no CHECK change.
 */
enum ContactKind: string implements TranslatableInterface
{
    case VenueContact = 'venue_contact';
    case Couple = 'couple';
    case Other = 'other';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('contact.kind.'.$this->value, [], 'crm', $locale);
    }
}
