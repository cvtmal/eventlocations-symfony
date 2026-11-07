<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Venues\ValueObject\TierLabel;
use App\Venues\ValueObject\VenuePlace;

/**
 * The Eintrag on a Location's record (CRM-1, #56), also a deal's venue. `id` is what the release form
 * posts, `number` what the venue editor's URL takes.
 */
final readonly class CompanyRecordVenue
{
    public function __construct(
        public string $id,
        public int $number,
        public string $name,
        public TierLabel $tier,
        public VenuePlace $place,
    ) {
    }
}
