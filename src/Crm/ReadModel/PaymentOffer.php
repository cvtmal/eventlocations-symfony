<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Venues\ValueObject\TierLabel;

/**
 * A won deal whose paid tier the venue has not been paid through yet: what «Zahlung erfassen» would record (G9).
 * `expected` (the venue's paid-until day, '' when it never paid) goes into the form, so a payment recorded while the
 * form was open is a conflict.
 */
final readonly class PaymentOffer
{
    public function __construct(
        public string $dealId,
        public string $venueName,
        public TierLabel $tier,
        public \DateTimeImmutable $startsOn,
        public \DateTimeImmutable $endsOn,
        public string $expected,
    ) {
    }
}
