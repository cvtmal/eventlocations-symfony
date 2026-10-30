<?php

declare(strict_types=1);

namespace App\Venues;

use App\Geo\Entity\Town;
use App\Geo\Repository\TownRepository;
use App\Venues\Exception\UnresolvedTown;

/**
 * Resolves a venue address to exactly one town by ZIP + name, optionally narrowed by the
 * canton (#9, D8). None or several is an UnresolvedTown; the caller words the message.
 *
 * Callers: the content import (ImportVenueContentHandler) and the CMS (#24 RelocateVenue).
 */
final class TownResolver
{
    public function __construct(
        private readonly TownRepository $towns,
    ) {
    }

    /**
     * @throws UnresolvedTown
     */
    public function resolve(string $zip, string $townName, ?string $cantonCode): Town
    {
        $towns = $this->towns->findByZipAndName($zip, $townName, $cantonCode);
        if (1 === \count($towns)) {
            return $towns[0];
        }

        $place = trim(\sprintf('%s %s %s', $zip, $townName, $cantonCode ?? ''));

        throw [] === $towns ? UnresolvedTown::unknown($place) : UnresolvedTown::ambiguous($place, \count($towns));
    }
}
