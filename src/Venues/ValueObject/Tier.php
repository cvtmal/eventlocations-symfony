<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The paid ranking tier of a venue (S6), best first.
 *
 * The value is stored in `venues.tier` (chk_venues_tier); the database derives
 * `venues.tier_rank` from it with the same ranks as rank(), so listings order by
 * tier_rank. `basic` shows no badge; its label exists for completeness.
 */
enum Tier: string implements TranslatableInterface
{
    case BeliebteLocation = 'beliebte_location';
    case Empfehlung = 'empfehlung';
    case TopPremium = 'top_premium';
    case Premium = 'premium';
    case Basic = 'basic';

    /**
     * 1 (best) … 5, equal to the generated column venues.tier_rank.
     */
    public function rank(): int
    {
        return match ($this) {
            self::BeliebteLocation => 1,
            self::Empfehlung => 2,
            self::TopPremium => 3,
            self::Premium => 4,
            self::Basic => 5,
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('tier.'.$this->value, [], 'venues', $locale);
    }
}
