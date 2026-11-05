<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The fields of a contact row on a Location's card (CRM-2, #55): the allow-list whose rules
 * `PropertyConstraints::contact()` holds for the card's dialog form.
 */
enum ContactProperty: string implements TranslatableInterface
{
    case FirstName = 'first_name';
    case LastName = 'last_name';
    case Email = 'email';
    case Phone = 'phone';
    case Position = 'position';

    /** Whether the field may be emptied (every field but the last name). */
    public function isNullable(): bool
    {
        return self::LastName !== $this;
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('contact.property.'.$this->value, [], 'crm', $locale);
    }
}
