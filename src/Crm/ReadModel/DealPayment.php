<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * The payment side of a deal's record (G9): the payment it can record, if any, and the venue's paid-until day with
 * the cancellation of its latest term (the manager's "do not renew": do not invoice the next term). `freeOfCharge`
 * says that latest term was granted without payment (a pilot); `void` is that term while staff may still void it.
 */
final readonly class DealPayment
{
    public function __construct(
        public ?PaymentOffer $offer,
        public ?\DateTimeImmutable $paidUntil,
        public ?\DateTimeImmutable $cancelRequestedAt,
        public bool $freeOfCharge = false,
        public ?VoidOffer $void = null,
    ) {
    }
}
