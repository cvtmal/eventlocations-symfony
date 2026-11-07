<?php

declare(strict_types=1);

namespace App\Search\ValueObject;

/**
 * The kinds of listing landing page (#17): `/eventlokale/{canton}`,
 * `/eventlokale/{canton}/{town}` and `/eventlokale/{building}`.
 */
enum LandingType: string
{
    case Canton = 'canton';
    case Town = 'town';
    case Building = 'building';
}
