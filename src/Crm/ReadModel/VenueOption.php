<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Venues\ValueObject\VenuePlace;

/**
 * A venue offered by the "Eintrag zuordnen" combobox (CRM-1): its id is what LinkVenue takes, and the
 * company it already belongs to is shown, because a venue belongs to one company at most.
 */
final readonly class VenueOption
{
    public function __construct(
        public string $id,
        public int $number,
        public string $name,
        public VenuePlace $place,
        public ?string $companyName,
    ) {
    }
}
