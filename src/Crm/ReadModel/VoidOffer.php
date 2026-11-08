<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Venues\ValueObject\TierLabel;

/**
 * The venue's latest term, not ended yet, that «Zahlung stornieren» would void (G9 finish, D42). Its id, tier and days
 * go into the form and the command, so a term recorded or voided while the form was open is a conflict.
 */
final readonly class VoidOffer
{
    public bool $freeOfCharge;

    public function __construct(
        public string $subscriptionId,
        public int $venueNumber,
        public string $venueName,
        public TierLabel $tier,
        public \DateTimeImmutable $startsOn,
        public \DateTimeImmutable $endsOn,
        public int $amountRappen,
    ) {
        $this->freeOfCharge = 0 === $amountRappen;
    }
}
