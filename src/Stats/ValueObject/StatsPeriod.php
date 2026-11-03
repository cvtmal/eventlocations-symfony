<?php

declare(strict_types=1);

namespace App\Stats\ValueObject;

/**
 * The period a statistics screen shows (#47): the last 7, 30, 90 or 365 days, today included,
 * because the staff button can load today's events.
 */
enum StatsPeriod: int
{
    case Days7 = 7;
    case Days30 = 30;
    case Days90 = 90;
    case Days365 = 365;

    /**
     * The `?zeitraum=` value of a screen; anything unknown falls back to 30 days.
     */
    public static function fromQuery(?string $value): self
    {
        if (null === $value || !ctype_digit($value)) {
            return self::Days30;
        }

        return self::tryFrom((int) $value) ?? self::Days30;
    }

    /**
     * The first day of the period that ends on $today (inclusive). Named firstDay() because a
     * backed enum already has the static from().
     */
    public function firstDay(\DateTimeImmutable $today): \DateTimeImmutable
    {
        return $today->setTime(0, 0)->modify(\sprintf('-%d days', $this->value - 1));
    }
}
