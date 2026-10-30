<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The price level the venue states for itself (S14), cheapest first.
 *
 * The value is stored in `venues.price_level` (chk_venues_price_level); NULL means unknown.
 */
enum PriceLevel: string implements TranslatableInterface
{
    case Moderate = 'moderate';
    case Medium = 'medium';
    case Exclusive = 'exclusive';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('price_level.'.$this->value, [], 'venues', $locale);
    }
}
