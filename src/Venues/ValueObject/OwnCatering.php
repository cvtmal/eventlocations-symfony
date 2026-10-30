<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * FAQ answer "Eigenes Catering?" (DP-9). Stored in the nullable `venues.own_catering`
 * (chk_venues_own_catering); NULL means unknown.
 */
enum OwnCatering: string implements TranslatableInterface
{
    case Allowed = 'allowed';
    case FixedCaterer = 'fixed_caterer';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('own_catering.'.$this->value, [], 'venues', $locale);
    }
}
