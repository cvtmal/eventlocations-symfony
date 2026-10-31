<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ReadModel\ContactListFilter;
use App\Crm\ValueObject\ContactKind;

/**
 * The contact list's filter, read from the query string.
 */
final class ContactFilterData
{
    public ?string $q = null;
    public ?ContactKind $kind = null;
    public ?string $owner = null;

    public function toFilter(): ContactListFilter
    {
        return new ContactListFilter($this->q, $this->kind, $this->owner);
    }

    public function isFiltered(): bool
    {
        return null !== $this->q || null !== $this->kind || null !== $this->owner;
    }
}
