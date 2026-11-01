<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The inline-editable fields of a company record (CRM-1, D10): the allow-list of the `field` a
 * PATCH may name. The value is the JSON field name; `owner` takes an admin user id, `stage` a
 * CompanyStage value. `stage` is writable while the company has no won deal (#28's D9).
 */
enum CompanyProperty: string implements TranslatableInterface
{
    case Name = 'name';
    case LegalName = 'legal_name';
    case Website = 'website';
    case Phone = 'phone';
    case Email = 'email';
    case Address = 'address';
    case Stage = 'stage';
    case Owner = 'owner';
    case Notes = 'notes';

    /** Whether the field may be emptied (every field but the name). */
    public function isNullable(): bool
    {
        return self::Name !== $this && self::Stage !== $this;
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('company.property.'.$this->value, [], 'crm', $locale);
    }
}
