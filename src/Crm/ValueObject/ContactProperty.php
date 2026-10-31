<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The inline-editable fields of a contact record (CRM-2, D10): the allow-list of the `field` a
 * PATCH may name. `company` takes a company id, `owner` an admin user id, `kind` a ContactKind value.
 */
enum ContactProperty: string implements TranslatableInterface
{
    case FirstName = 'first_name';
    case LastName = 'last_name';
    case Email = 'email';
    case Phone = 'phone';
    case Position = 'position';
    case Kind = 'kind';
    case Company = 'company';
    case Owner = 'owner';

    /** Whether the field may be emptied (every field but the last name and the kind). */
    public function isNullable(): bool
    {
        return self::LastName !== $this && self::Kind !== $this;
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('contact.property.'.$this->value, [], 'crm', $locale);
    }
}
