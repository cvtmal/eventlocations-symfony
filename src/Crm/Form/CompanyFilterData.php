<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ReadModel\CompanyListFilter;
use App\Crm\ValueObject\CompanyStage;

/**
 * The company list's filter, read from the query string. The type's choices are the only values it
 * accepts, so `toFilter()` never builds a filter with an unknown value.
 */
final class CompanyFilterData
{
    public ?string $q = null;
    public ?CompanyStage $stage = null;
    public ?string $owner = null;
    public ?string $venues = null;

    public function toFilter(): CompanyListFilter
    {
        return new CompanyListFilter($this->q, $this->stage, $this->owner, $this->venues);
    }

    public function isFiltered(): bool
    {
        return null !== $this->q || null !== $this->stage || null !== $this->owner || null !== $this->venues;
    }
}
