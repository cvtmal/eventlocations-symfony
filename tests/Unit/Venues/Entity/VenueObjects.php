<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues\Entity;

use App\Geo\Entity\Canton;
use App\Geo\Entity\Town;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenueStatus;

/**
 * Builds unpersisted Geo and Venue entities for the entity unit tests.
 */
final class VenueObjects
{
    public static function canton(string $code = 'zh'): Canton
    {
        return new Canton($code, 1, 'canton-'.$code, 47.4, 8.6);
    }

    public static function town(?Canton $canton = null, string $name = 'Stäfa'): Town
    {
        return new Town($canton ?? self::canton(), '8712', $name, 'staefa', 158, 47.24, 8.72, 14700);
    }

    public static function venue(?Town $town = null): Venue
    {
        $town ??= self::town();

        return new Venue('Seehaus Mittelwil', Tier::Premium, VenueStatus::Published, 150, 20, $town, $town->zip(), 'Seestrasse 1', $town->lat(), $town->lng());
    }
}
