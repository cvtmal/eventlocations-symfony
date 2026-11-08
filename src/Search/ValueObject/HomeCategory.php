<?php

declare(strict_types=1);

namespace App\Search\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The home page's category tiles (HP-3), one per event type (F5 `filter-event`, E5). The value
 * is a `suitable_for` code of the listing's `event[]` filter; the label is the home page's own,
 * not the filter's.
 */
enum HomeCategory: string implements TranslatableInterface
{
    case CorporateEvent = 'corporate_event';
    case Seminar = 'seminar';
    case Apero = 'apero';
    case ChristmasParty = 'christmas_party';
    case Birthday = 'birthday';
    // D39: Generalversammlung in place of Hochzeit, which gets no landing page and no push (D31).
    case GeneralAssembly = 'general_assembly';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('home.category.'.$this->value, [], 'search', $locale);
    }

    public function icon(): string
    {
        return match ($this) {
            self::CorporateEvent => 'briefcase',
            self::Seminar => 'presentation',
            self::Apero => 'wine',
            self::ChristmasParty => 'gift',
            self::Birthday => 'cake',
            self::GeneralAssembly => 'users',
        };
    }
}
