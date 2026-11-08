<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\LastMinuteOffer;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * An offer «Kurzfristig frei» (G14): one day a week from today (the real clock; tests that mock the time pass
 * `startsOn` and `endsOn`), the whole location, no event types. The factory writes what it is given, without the
 * handler's rules: a test can store an offer that has expired or belongs to a Free venue.
 *
 * @extends PersistentObjectFactory<LastMinuteOffer>
 */
final class LastMinuteOfferFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return LastMinuteOffer::class;
    }

    /**
     * The last day was yesterday: hidden on every page, deleted by the next nightly run.
     */
    public function expired(): self
    {
        return $this->with([
            'startsOn' => new \DateTimeImmutable('today -3 days'),
            'endsOn' => new \DateTimeImmutable('yesterday'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        $startsOn = new \DateTimeImmutable('today +7 days');

        return [
            'venue' => VenueFactory::new(),
            'room' => null,
            'startsOn' => $startsOn,
            'endsOn' => $startsOn,
            'eventTypes' => [],
            'priceText' => 'Seminarpauschale ab CHF 89 pro Person',
            'minGuests' => null,
            'terms' => null,
            'now' => new \DateTimeImmutable(),
        ];
    }
}
