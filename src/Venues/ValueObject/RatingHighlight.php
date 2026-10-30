<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The nine rating highlights a venue can show (up to three, DP-6), in the spec's order.
 * Stored in `venue_rating_highlights.code` (chk_venue_rating_highlights_code). The legacy
 * import (#21) maps eventlokale's `sla_rating` 1004–1012 onto these codes.
 */
enum RatingHighlight: string implements TranslatableInterface
{
    case Food = 'food';
    case Atmosphere = 'atmosphere';
    case Architecture = 'architecture';
    case Location = 'location';
    case Hospitality = 'hospitality';
    case Reachability = 'reachability';
    case Equipment = 'equipment';
    case Programme = 'programme';
    case Value = 'value';

    /**
     * The `<symbol id>` in assets/icons/sprite.svg (#1's Lucide sprite).
     */
    public function icon(): string
    {
        return match ($this) {
            self::Food => 'utensils',
            self::Atmosphere => 'sparkles',
            self::Architecture => 'landmark',
            self::Location => 'binoculars',
            self::Hospitality => 'smile',
            self::Reachability => 'route',
            self::Equipment => 'sofa',
            self::Programme => 'music',
            self::Value => 'coins',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('rating_highlight.'.$this->value, [], 'venues', $locale);
    }
}
