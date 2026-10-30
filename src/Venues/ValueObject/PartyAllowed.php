<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Whether the venue allows a party after the dinner (S14).
 *
 * The value is stored in `venues.party` (chk_venues_party); NULL means unknown. The curfew
 * is separate (`has_curfew`, `curfew_at`), the venue's own wording is `party_note`.
 */
enum PartyAllowed: string implements TranslatableInterface
{
    case Yes = 'yes';
    case Limited = 'limited';
    case No = 'no';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('party.'.$this->value, [], 'venues', $locale);
    }
}
