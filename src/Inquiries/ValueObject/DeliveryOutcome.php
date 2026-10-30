<?php

declare(strict_types=1);

namespace App\Inquiries\ValueObject;

/**
 * What `DeliverInquiry` tells the controller: the guest sees a failure only when the venue
 * did not get the inquiry; a failed guest copy is recorded on the row and nothing more.
 */
final readonly class DeliveryOutcome
{
    public function __construct(
        public bool $venueMailSent,
    ) {
    }
}
