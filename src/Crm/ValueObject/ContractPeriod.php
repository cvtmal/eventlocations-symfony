<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * The next contract period of a renewal (N12). It starts the day after the old end and runs as long as the old one, as
 * a calendar length, so whole years and months survive a leap year: `2026-03-01…2027-02-28` becomes
 * `2027-03-01…2028-02-29`. Without a start the old period counts as one year.
 */
final readonly class ContractPeriod
{
    private function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }

    public static function after(?\DateTimeImmutable $start, \DateTimeImmutable $end): self
    {
        $next = $end->modify('+1 day');
        $length = null === $start ? new \DateInterval('P1Y') : $start->diff($next);

        return new self($next, $next->add($length)->modify('-1 day'));
    }
}
