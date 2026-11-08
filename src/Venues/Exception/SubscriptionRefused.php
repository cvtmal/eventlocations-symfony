<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * A term write that the venue's terms do not allow (G9): a new term overlapping one already paid, a tier without a
 * price, a term wholly in the past, a term free of charge without a note, an amount out of range, voiding a term a later one
 * follows, or cancelling or voiding a term that has already ended. `reason` is one of the constants, for the caller's message.
 */
final class SubscriptionRefused extends DomainException
{
    public const string OVERLAP = 'overlap';
    public const string FREE_TIER = 'free_tier';
    public const string ENDED = 'ended';
    public const string PAST = 'past';
    public const string NOTE = 'note';
    public const string AMOUNT = 'amount';
    public const string LATER = 'later';

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

    public static function freeWithoutNote(int $venueNumber): self
    {
        return new self(self::NOTE, \sprintf('A term free of charge for venue %d needs a note saying why.', $venueNumber));
    }

    public static function amountOutOfRange(int $venueNumber, int $amountRappen): self
    {
        return new self(self::AMOUNT, \sprintf('A term of venue %d cannot cost %d Rappen.', $venueNumber, $amountRappen));
    }

    public static function laterTermFollows(string $subscriptionId): self
    {
        return new self(self::LATER, \sprintf('The subscription "%s" is followed by a later term: void that one first.', $subscriptionId));
    }

    public static function ended(string $subscriptionId): self
    {
        return new self(self::ENDED, \sprintf('The subscription "%s" has already ended.', $subscriptionId));
    }
}
