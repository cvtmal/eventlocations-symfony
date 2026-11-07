<?php

declare(strict_types=1);

namespace App\Stats\ValueObject;

/**
 * How many weeks the funnel scorecard shows (G8), the current week included. The set is closed on purpose: it
 * is the query's ceiling (at most 52 weeks x 8 steps of output, one bounded time range).
 */
enum FunnelWindow: int
{
    case Weeks4 = 4;
    case Weeks12 = 12;
    case Weeks26 = 26;
    case Weeks52 = 52;

    /**
     * The `?wochen=` value of the screen; anything unknown falls back to 12 weeks.
     */
    public static function fromQuery(?string $value): self
    {
        if (null === $value || !ctype_digit($value)) {
            return self::Weeks12;
        }

        return self::tryFrom((int) $value) ?? self::Weeks12;
    }

    /**
     * Monday 00:00 of the first week of the window that ends in the week of $today (same time zone as $today).
     * Named firstWeek() because a backed enum already has the static from().
     */
    public function firstWeek(\DateTimeImmutable $today): \DateTimeImmutable
    {
        return $today->modify('monday this week')->setTime(0, 0)->modify(\sprintf('-%d weeks', $this->value - 1));
    }
}
