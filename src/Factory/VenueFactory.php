<?php

declare(strict_types=1);

namespace App\Factory;

use App\Geo\Entity\Canton;
use App\Geo\Entity\Town;
use App\Venues\Entity\Tier;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\VenueStatus;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * Needs imported towns (app:geo:import-towns): without a `town` attribute it picks a random
 * one and takes zip, lat and lng from it; `withoutLocation()` makes a venue with none of
 * them. The name is fictional; uniqueness is not required (the slug on VenueTranslation is
 * unique per locale). A paid `tier` also gets a paid term from 2000 to 2099 (G9).
 *
 * @extends PersistentObjectFactory<Venue>
 */
final class VenueFactory extends PersistentObjectFactory
{
    /** The term a venue created on a paid tier gets. */
    public const string TERM_FROM = '2000-01-01';
    public const string TERM_TO = '2099-12-31';

    /** Numbers the default inquiry addresses, so they are unique and need no faker draw. */
    private static int $inquiryAddresses = 0;

    private const NAME_PREFIXES = ['Landgut', 'Seehaus', 'Gasthof', 'Schlossgut', 'Waldhaus', 'Villa', 'Remise', 'Gutshof'];

    public static function class(): string
    {
        return Venue::class;
    }

    public function withContact(): self
    {
        return $this->afterInstantiate(static fn (Venue $venue) => $venue->changeContact(
            '+4144'.self::faker()->numerify('#######'),
            'info@'.self::faker()->unique()->domainWord().'.example.ch',
            'https://'.self::faker()->domainWord().'.example.ch',
            self::faker()->name(),
        ));
    }

    public function withDistances(): self
    {
        return $this->afterInstantiate(static fn (Venue $venue) => $venue->changeDistances(
            (string) self::faker()->randomFloat(1, 0.1, 15),
            $venue->town()?->name(),
            (string) self::faker()->randomFloat(1, 0.5, 40),
            null,
            (string) self::faker()->randomFloat(1, 5, 150),
            null,
        ));
    }

    /**
     * A venue with no address (#21), optionally with the canton its source named.
     */
    public function withoutLocation(?Canton $canton = null): self
    {
        return $this->with(['town' => null, 'zip' => null, 'street' => null, 'lat' => null, 'lng' => null, 'canton' => $canton]);
    }

    public function withFaq(): self
    {
        return $this->afterInstantiate(static fn (Venue $venue) => $venue->answerFaq(
            self::faker()->randomElement([true, false, null]),
        ));
    }

    /**
     * G12: an entry whose facts staff have not checked, so it is not indexed (every factory venue is checked by
     * default, `initialize()`).
     */
    public function withUncheckedFacts(): self
    {
        return $this->afterInstantiate(static function (Venue $venue): void {
            $venue->confirmFacts(false, new \DateTimeImmutable());
        });
    }

    /**
     * D60: a venue without an inquiry address, which no publish path puts online (every factory venue has one by
     * default, `initialize()`). Keeps the other contact fields.
     */
    public function withoutInquiryEmail(): self
    {
        return $this->afterInstantiate(static fn (Venue $venue) => $venue->changeContact($venue->phone(), null, $venue->website(), $venue->contactPerson()));
    }

    public function withRoomFacts(int $roomCount, ?int $largestRoomM2, ?string $maxRoomHeightM): self
    {
        return $this->afterInstantiate(static fn (Venue $venue) => $venue->summariseRooms($roomCount, $largestRoomM2, $maxRoomHeightM));
    }

    protected function initialize(): static
    {
        // G12: checked by default, so a factory venue with a description passes VenueIndexGate; withUncheckedFacts() undoes it.
        // D60: an inquiry address by default, as every online venue has one; withoutInquiryEmail() undoes it. Not from
        // faker, so the seeded draws of the demo data stay as they were.
        return $this->afterInstantiate(static function (Venue $venue): void {
            $venue->confirmFacts(true, new \DateTimeImmutable('2026-01-01 00:00:00+01:00'));
            $venue->changeContact(null, \sprintf('anfragen-%d@example.ch', ++self::$inquiryAddresses), null, null);
        })->afterPersist(static function (Venue $venue): void {
            // A paid tier comes with a paid term (G9): the entitlements read the term, not the tier. This one covers
            // every date a test mocks; a test about terms writes its own (SubscriptionFactory) or removes this one.
            if ($venue->tier()->priceRappen() > 0) {
                SubscriptionFactory::createOne([
                    'venue' => $venue,
                    'tier' => $venue->tier(),
                    'startsOn' => new \DateTimeImmutable(self::TERM_FROM),
                    'endsOn' => new \DateTimeImmutable(self::TERM_TO),
                ]);
            }
        })->beforeInstantiate(static function (array $attributes): array {
            // A tier is named by its code ('free', 'pro', KnownTiers) or passed as the entity; the seeded rows come from the migration.
            $tier = $attributes['tier'] ?? KnownTiers::FREE;
            if (\is_string($tier)) {
                $tier = repository(Tier::class)->findOneBy(['code' => $tier]) ?? throw new \LogicException(\sprintf('No tier "%s".', $tier));
            }
            $attributes['tier'] = $tier;

            // `??` below would replace an explicit null, which is withoutLocation().
            if (\array_key_exists('town', $attributes) && null === $attributes['town']) {
                return $attributes;
            }

            $town = $attributes['town'] ?? repository(Town::class)->random();
            \assert($town instanceof Town);

            return [
                'town' => $town,
                'zip' => $attributes['zip'] ?? $town->zip(),
                'lat' => $attributes['lat'] ?? $town->lat(),
                'lng' => $attributes['lng'] ?? $town->lng(),
            ] + $attributes;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'name' => self::faker()->randomElement(self::NAME_PREFIXES).' '.self::faker()->lastName(),
            'status' => VenueStatus::Published,
            'capacityMax' => 120,
            'capacityMin' => null,
            'street' => self::faker()->streetAddress(),
        ];
    }
}
