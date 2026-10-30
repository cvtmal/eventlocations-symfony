<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueListFilter;
use App\Venues\ValueObject\Tier;

/**
 * The venue list's filter, read from the query string. The choices of the type are the only values it
 * accepts, so `toFilter()` never builds a `VenueListFilter` with an unknown status.
 */
final class VenueFilterData
{
    public const string ADDRESS_MISSING = 'missing';

    public ?string $q = null;
    public ?Tier $tier = null;
    public ?string $canton = null;
    public ?string $address = null;
    public ?string $status = null;

    public function toFilter(): VenueListFilter
    {
        return new VenueListFilter($this->q, $this->status, $this->tier, $this->canton, self::ADDRESS_MISSING === $this->address);
    }
}
