<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * A term write that the venue's terms do not allow (G9): a new term overlapping one already paid, a tier without a
 * price, a term wholly in the past, or cancelling a term that has already ended. `reason` is one of the constants, for the caller's message.
 */
final class SubscriptionRefused extends DomainException
{
    public const string OVERLAP = 'overlap';
    public const string FREE_TIER = 'free_tier';
    public const string ENDED = 'ended';
    public const string PAST = 'past';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function overlaps(int $venueNumber, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn): self
    {
        return new self(self::OVERLAP, \sprintf('Venue %d already has a paid term between %s and %s.', $venueNumber, $startsOn->format('Y-m-d'), $endsOn->format('Y-m-d')));
    }

    public static function tierWithoutPrice(string $tierCode): self
    {
        return new self(self::FREE_TIER, \sprintf('The tier "%s" has no price, so it is never paid for.', $tierCode));
    }

    public static function inThePast(\DateTimeImmutable $endsOn): self
    {
        return new self(self::PAST, \sprintf('A term that ended on %s grants nothing.', $endsOn->format('Y-m-d')));
    }

    public static function ended(string $subscriptionId): self
    {
        return new self(self::ENDED, \sprintf('The subscription "%s" has already ended.', $subscriptionId));
    }
}
