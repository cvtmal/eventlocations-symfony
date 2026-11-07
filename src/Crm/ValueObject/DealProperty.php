<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

use App\Crm\Exception\InvalidPropertyValue;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The inline-editable fields of a deal record (CRM-7): the allow-list of the `field` a PATCH may name. `venue` and
 * `owner` take ids, `tier` a tier's code (`tiers.code`, F12), `amount` francs as typed, the three dates `Y-m-d`. The company never
 * changes (delete and recreate) and `stage` moves only through `MoveDealStage`, so neither is here.
 */
enum DealProperty: string implements TranslatableInterface
{
    case Name = 'name';
    case Venue = 'venue';
    case Tier = 'tier';
    case Amount = 'amount';
    case ContractStart = 'contract_start';
    case ContractEnd = 'contract_end';
    case ExpectedClose = 'expected_close';
    case Owner = 'owner';

    /** Whether the field may be emptied (every field but the name). */
    public function isNullable(): bool
    {
        return self::Name !== $this;
    }

    /** Whether it holds a calendar day. */
    public function isDate(): bool
    {
        return \in_array($this, [self::ContractStart, self::ContractEnd, self::ExpectedClose], true);
    }

    /**
     * The stored form of what was typed, which is the field's version too (D11): Rappen for the amount, `Y-m-d` for
     * a date, else `PropertyValue::canonical()`. An `expected` is compared as it is, because the island sends the
     * stored form back; only a new value comes through here.
     *
     * @throws InvalidPropertyValue when an amount or a date cannot be read
     */
    public function canonical(?string $typed): ?string
    {
        $value = PropertyValue::canonical($typed);
        if (null === $value) {
            return null;
        }
        if ($this->isDate()) {
            return TaskDueDate::parse($value)?->format('Y-m-d') ?? throw InvalidPropertyValue::unknown($this->value);
        }
        if (self::Amount === $this) {
            try {
                return (string) Chf::parse($value);
            } catch (\InvalidArgumentException) {
                throw InvalidPropertyValue::unknown($this->value);
            }
        }

        return $value;
    }

    /**
     * What a row shows for a stored value when that is not the value itself (N4): `CHF 1'250.50`, `31.12.2027`. Null
     * for every other field, whose row finds the label among its options or shows the text.
     */
    public function display(?string $stored): ?string
    {
        if (null === $stored) {
            return null;
        }
        if (self::Amount === $this) {
            return Chf::display((int) $stored);
        }

        return $this->isDate() ? TaskDueDate::parse($stored)?->format('d.m.Y') : null;
    }

    /**
     * The text a row edits when that is not the stored value: the amount in francs, as `canonical()` reads it. Null for
     * every other field, whose stored value is the text. The API reads what is sent as francs, so an amount edited as
     * its stored Rappen would be a hundred times too high after an idle save.
     */
    public function draft(?string $stored): ?string
    {
        return self::Amount === $this && null !== $stored ? Chf::format((int) $stored) : null;
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('deal.property.'.$this->value, [], 'crm', $locale);
    }
}
