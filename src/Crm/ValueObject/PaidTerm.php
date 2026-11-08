<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

use App\Venues\ValueObject\TermDay;

/**
 * The term a deal's payment buys (G9): the deal's contract period. A missing start is the day after the venue's
 * paid-until day when that is still running (the renewal follows on), else today; a missing end is one year after
 * the start, a missing start with an end set is one year before it. `MarkDealPaidHandler` writes a missing date back
 * to the deal, so `RenewalTasks` see the period.
 */
final readonly class PaidTerm
{
    private function __construct(
        public \DateTimeImmutable $startsOn,
        public \DateTimeImmutable $endsOn,
    ) {
    }

    public static function forDeal(?\DateTimeImmutable $contractStartOn, ?\DateTimeImmutable $contractEndOn, ?\DateTimeImmutable $paidUntil, \DateTimeImmutable $today): self
    {
        $today = $today->setTime(0, 0);
        $start = $contractStartOn?->setTime(0, 0);
        $end = $contractEndOn?->setTime(0, 0);
        if (null === $start && null !== $end) {
            $start = $end->modify('-1 year')->modify('+1 day');
        }
        if (null === $start) {
            $start = null !== $paidUntil && $paidUntil->setTime(0, 0) >= $today ? $paidUntil->setTime(0, 0)->modify('+1 day') : $today;
        }

        return new self($start, $end ?? TermDay::oneYearFrom($start));
    }
}
