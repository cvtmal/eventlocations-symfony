<?php

declare(strict_types=1);

namespace App\Tests\Support\Search;

use App\Factory\VenueFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Canton as CantonRow;
use App\Geo\Entity\Town;
use App\Geo\ValueObject\Canton;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenueStatus;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * Published venues in the shapes that switch landing pages on (#17): in a canton without a
 * town, in a town, with a building term. Needs the imported towns for inTown().
 */
trait LandingFixtures
{
    /**
     * Venues in the canton without a town, so no town page comes with them.
     *
     * @return list<Venue>
     */
    private function inCanton(Canton $canton, int $count, VenueStatus $status = VenueStatus::Published): array
    {
        $row = repository(CantonRow::class)->find($canton->value);
        \assert($row instanceof CantonRow);

        $venues = [];
        for ($i = 0; $i < $count; ++$i) {
            $venue = VenueFactory::new()->withoutLocation($row)->create(['status' => $status]);
            VenueTranslationFactory::createOne(['venue' => $venue]);
            $venues[] = $venue;
        }

        return $venues;
    }

    /**
     * @return list<Venue>
     */
    private function inTown(string $town, int $count): array
    {
        $venues = [];
        for ($i = 0; $i < $count; ++$i) {
            $venue = VenueFactory::createOne(['town' => $this->town($town)]);
            VenueTranslationFactory::createOne(['venue' => $venue]);
            $venues[] = $venue;
        }

        return $venues;
    }

    /**
     * @param list<Venue> $venues
     *
     * @return list<Venue>
     */
    private function withBuilding(array $venues, string $code): array
    {
        foreach ($venues as $venue) {
            VenueTermFactory::new()->term(TermGroup::Building, $code)->create(['venue' => $venue]);
        }

        return $venues;
    }

    private function town(string $name): Town
    {
        $town = repository(Town::class)->findOneBy(['name' => $name]);
        \assert($town instanceof Town);

        return $town;
    }
}
