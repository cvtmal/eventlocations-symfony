<?php

declare(strict_types=1);

namespace App\Crm;

use App\Crm\Command\ImportLocation;
use App\Crm\ValueObject\ContactBasis;
use App\Crm\ValueObject\ContactChannel;
use App\Crm\ValueObject\ContactEmail;
use App\Crm\ValueObject\Outreach;
use App\Crm\ValueObject\OutreachCohort;
use App\Crm\ValueObject\PropertyValue;

/**
 * One row of the contact sheet (F14, {@see ContactSheet}): the cells as written, trimmed, by column name.
 */
final readonly class ContactSheetRow
{
    /** The person columns; any of them needs `last_name`. */
    public const array CONTACT_COLUMNS = ['first_name', 'last_name', 'position', 'contact_email', 'contact_phone'];

    /**
     * @param int                   $line  the row's line in the file, the header being line 1
     * @param array<string, string> $cells by column of ContactSheet::HEADER
     */
    public function __construct(
        public int $line,
        private array $cells,
    ) {
    }

    /** The cell as the CRM stores it: blanks collapsed, empty is null (`PropertyValue::canonical()`). */
    public function value(string $column): ?string
    {
        return PropertyValue::canonical($this->cells[$column] ?? '', \in_array($column, ['email', 'contact_email'], true));
    }

    public function hasContact(): bool
    {
        foreach (self::CONTACT_COLUMNS as $column) {
            if (null !== $this->value($column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The row's addresses, lower case, each once.
     *
     * @return list<string>
     */
    public function emails(): array
    {
        return array_values(array_unique(array_filter([ContactEmail::normalise($this->value('email')), ContactEmail::normalise($this->value('contact_email'))], static fn (?string $email): bool => null !== $email && '' !== $email)));
    }

    public function outreach(): Outreach
    {
        $cohort = $this->value('cohort');
        $channel = $this->value('channel');
        $basis = $this->value('basis');

        return new Outreach(
            $this->value('campaign'),
            null === $cohort ? null : OutreachCohort::tryFrom(strtoupper($cohort)),
            null === $channel ? null : ContactChannel::tryFrom($channel),
            null === $basis ? null : ContactBasis::tryFrom($basis),
        );
    }

    public function toCommand(string $companyId, string $contactId, ?int $venueNumber): ImportLocation
    {
        $contact = $this->hasContact();

        return new ImportLocation(
            $companyId,
            (string) $this->value('location'),
            $this->value('address'),
            $this->value('email'),
            $this->value('phone'),
            $this->value('website'),
            $contact ? $contactId : null,
            $contact ? $this->value('first_name') : null,
            $contact ? $this->value('last_name') : null,
            $contact ? $this->value('position') : null,
            $contact ? $this->value('contact_email') : null,
            $contact ? $this->value('contact_phone') : null,
            $this->outreach(),
            $venueNumber,
        );
    }
}
