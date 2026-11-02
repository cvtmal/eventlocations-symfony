<?php

declare(strict_types=1);

namespace App\Search\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The home page's category tiles (HP-3, S15) in the board's order. The value is a building code
 * of the listing filter; the label is the home page's own, not the filter's.
 */
enum HomeCategory: string implements TranslatableInterface
{
    case Castle = 'castle';
    case EventHall = 'event_hall';
    case Restaurant = 'restaurant';
    case Winery = 'brewery_winery';
    case Hotel = 'hotel';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('home.category.'.$this->value, [], 'search', $locale);
    }

    public function icon(): string
    {
        return match ($this) {
            self::Castle => 'castle',
            self::EventHall => 'warehouse',
            self::Restaurant => 'beer',
            self::Winery => 'grape',
            self::Hotel => 'hotel',
        };
    }
}
