<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use App\Venues\ValueObject\VenuePlace;

/**
 * A location that waits for staff. The manager is null once the account is deleted: the row outlives it.
 */
final readonly class PendingSubmissionRow
{
    public function __construct(
        public string $venueId,
        public int $number,
        public string $name,
        public VenuePlace $place,
        public ?string $managerFirstName,
        public ?string $managerLastName,
        public ?string $managerEmail,
        public \DateTimeImmutable $submittedAt,
        /** The default locale's slug, for the public link in the acceptance mail (G6). */
        public ?string $slug = null,
    ) {
    }
}
