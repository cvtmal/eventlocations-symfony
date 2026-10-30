<?php

declare(strict_types=1);

namespace App\Geo\ValueObject;

/**
 * What a place suggestion points at.
 */
enum PlaceType: string
{
    case Canton = 'canton';
    case Town = 'town';
}
