<?php

declare(strict_types=1);

namespace App\Factory;

use App\Geo\Entity\Canton;
use App\Geo\Entity\Town;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenueStatus;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * Needs imported towns (app:geo:import-towns): without a `town` attribute it picks a random
 * one and takes zip, lat and lng from it; `withoutLocation()` makes a venue with none of
 * them. The name is fictional; uniqueness is not required (the slug on VenueTranslation is
 * unique per locale).
 *
 * @extends PersistentObjectFactory<Venue>
 */
final class VenueFactory extends PersistentObjectFactory
{
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
            self::faker()->randomElement([...OwnCatering::cases(), null]),
            self::faker()->randomElement([true, false, null]),
        ));
    }

    public function withRoomFacts(int $roomCount, ?int $largestRoomM2, ?string $maxRoomHeightM): self
    {
        return $this->afterInstantiate(static fn (Venue $venue) => $venue->summariseRooms($roomCount, $largestRoomM2, $maxRoomHeightM));
    }

    protected function initialize(): static
    {
        return $this->beforeInstantiate(static function (array $attributes): array {
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
            'tier' => Tier::Basic,
            'status' => VenueStatus::Published,
            'capacityMax' => 120,
            'capacityMin' => null,
            'street' => self::faker()->streetAddress(),
        ];
    }
}
