<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;
use App\Venues\ValueObject\LastMinuteLimits;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * An offer «Kurzfristig frei» (G14) that breaks a rule of `LastMinuteLimits` or names a room or event type the venue
 * does not show. The message is a key of the `cms` catalogue with `parameters`; `field` names the form child the error
 * belongs to. The plan rules (Pro only, how many live offers) are `VenueRuleViolation`s of `TierLimits`.
 */
final class LastMinuteOfferRefused extends DomainException
{
    /**
     * @param array<string, int|string> $parameters
     */
    private function __construct(
        string $translationKey,
        public readonly string $field,
        public readonly array $parameters = [],
    ) {
        parent::__construct($translationKey);
    }

    /** The first day lies before today (Zurich). */
    public static function startInPast(): self
    {
        return new self('last_minute.error.start_in_past', 'startsOn');
    }

    public static function tooFarAhead(): self
    {
        return new self('last_minute.error.too_far_ahead', 'startsOn', ['days' => LastMinuteLimits::HORIZON_DAYS]);
    }

    /** The last day lies before the first. */
    public static function endBeforeStart(): self
    {
        return new self('last_minute.error.end_before_start', 'endsOn');
    }

    public static function periodTooLong(): self
    {
        return new self('last_minute.error.period_too_long', 'endsOn', ['days' => LastMinuteLimits::MAX_DAYS]);
    }

    /** The room is not one of the rooms the venue's page shows. */
    public static function foreignRoom(): self
    {
        return new self('last_minute.error.foreign_room', 'roomId');
    }

    /** An event type the venue does not list under «Geeignet für». */
    public static function eventTypeNotOffered(): self
    {
        return new self('last_minute.error.event_type_not_offered', 'eventTypes');
    }

    /**
     * The bus wraps what a handler throws. Returns the refusal inside, or null.
     */
    public static function within(HandlerFailedException $failure): ?self
    {
        foreach ($failure->getWrappedExceptions(self::class) as $refusal) {
            return $refusal;
        }

        return null;
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            self::startInPast(),
            self::tooFarAhead(),
            self::endBeforeStart(),
            self::periodTooLong(),
            self::foreignRoom(),
            self::eventTypeNotOffered(),
        ];
    }

    public function translationKey(): string
    {
        return $this->getMessage();
    }
}
