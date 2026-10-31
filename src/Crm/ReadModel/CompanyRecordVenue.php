<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenuePlace;

/**
 * A venue in the Locations card of a company (CRM-1). `id` is what the unlink form posts, `number`
 * what the venue editor's URL takes.
 */
final readonly class CompanyRecordVenue
{
    public function __construct(
        public string $id,
        public int $number,
        public string $name,
        public Tier $tier,
        public VenuePlace $place,
    ) {
    }
}
