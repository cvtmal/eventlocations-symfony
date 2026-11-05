<?php

declare(strict_types=1);

namespace App\Search;

use App\Geo\ReadModel\PlaceLookup;
use App\Geo\ReadModel\PlaceResolver;
use App\Geo\ValueObject\PlaceType;
use App\Search\ValueObject\NearbyCentre;
use App\Search\ValueObject\NearbyPoint;
use App\Search\ValueObject\NearbyRequest;

/**
 * Turns the near-me page's query (#52) into a centre. `lat`/`lng` win over `ort`; a parameter
 * that is not a string (`ort[]=`) counts as absent. A 4-digit `ort` is a ZIP (its most populous
 * town, the listing's rule); any other text takes the first town `PlaceLookup` suggests, never a
 * canton. Nothing here throws: a miss is a notice on a 200 page.
 */
final class NearbyCentres
{
    public function __construct(
        private readonly PlaceResolver $resolver,
        private readonly PlaceLookup $lookup,
    ) {
    }

    /**
     * @param array<array-key, mixed> $query
     */
    public function resolve(array $query): NearbyRequest
    {
        $ort = \is_string($query['ort'] ?? null) ? trim($query['ort']) : null;

        if (\array_key_exists('lat', $query) || \array_key_exists('lng', $query)) {
            $point = NearbyPoint::fromQuery($query['lat'] ?? null, $query['lng'] ?? null);

            return null === $point
                ? new NearbyRequest(null, NearbyRequest::POSITION, $ort)
                : new NearbyRequest(new NearbyCentre($point, null, null), null, $ort);
        }

        if (null === $ort || '' === $ort) {
            return new NearbyRequest(null, null, $ort);
        }

        $centre = $this->centreFor($ort);

        return null === $centre
            ? new NearbyRequest(null, NearbyRequest::NOT_FOUND, $ort)
            : new NearbyRequest($centre, null, $ort);
    }

    private function centreFor(string $ort): ?NearbyCentre
    {
        if (1 === preg_match('/^\d{4}$/', $ort)) {
            $place = $this->resolver->resolve($ort);

            return null === $place ? null : new NearbyCentre(NearbyPoint::town($place->lat, $place->lng), $place->label(), $ort);
        }

        foreach ($this->lookup->suggest($ort) as $suggestion) {
            if (PlaceType::Town === $suggestion->type) {
                \assert(null !== $suggestion->townId && null !== $suggestion->name && null !== $suggestion->zip);
                \assert(null !== $suggestion->lat && null !== $suggestion->lng);

                return new NearbyCentre(NearbyPoint::town($suggestion->lat, $suggestion->lng), $suggestion->zip.' '.$suggestion->name, $suggestion->townId);
            }
        }

        return null;
    }
}
