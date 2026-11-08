<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * The payment side of a deal's record (G9): the payment it can record, if any, and the venue's paid-until day with
 * the cancellation of its latest term (the manager's "do not renew": do not invoice the next term).
 */
final readonly class DealPayment
{
    public function __construct(
        public ?PaymentOffer $offer,
        public ?\DateTimeImmutable $paidUntil,
        public ?\DateTimeImmutable $cancelRequestedAt,
    ) {
    }
}
