<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ReadModel\DealFilter;

/**
 * The deal filter, read from the query string. The type's choices are the only values it accepts.
 */
final class DealFilterData
{
    public ?string $q = null;
    public ?string $stage = null;
    public ?string $owner = null;
    public ?string $month = null;

    public function toFilter(): DealFilter
    {
        return new DealFilter($this->q, $this->stage, $this->owner, $this->month);
    }

    /**
     * The board has no search and shows every stage (D12).
     */
    public function toBoardFilter(): DealFilter
    {
        return new DealFilter(owner: $this->owner, month: $this->month);
    }
}
