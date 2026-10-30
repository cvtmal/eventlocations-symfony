<?php

declare(strict_types=1);

namespace App\Cms\ValueObject;

/**
 * The route requirement of every back-office URL that names a venue by its public number. `venues.number`
 * is an `INTEGER`, so a value with ten digits could overflow it and answer a 500; nine digits keep every
 * matched value in range, and a longer one is the router's 404. #25's routes use it too, and
 * `ChecksAdminRoutes` fills a parameter with this requirement with a number instead of a UUID.
 */
final readonly class VenueNumber
{
    public const string REQUIREMENT = '[1-9]\d{0,8}';
}
