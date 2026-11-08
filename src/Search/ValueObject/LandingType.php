<?php

declare(strict_types=1);

namespace App\Search\ValueObject;

/**
 * The kinds of listing landing page (#17): `/eventlokale/{canton}`,
 * `/eventlokale/{canton}/{town}`, `/eventlokale/{building}` and (F8) an event type,
 * `/eventlokale/{event}` and `/eventlokale/{canton}/{event}` (an event page with a canton).
 */
enum LandingType: string
{
    case Canton = 'canton';
    case Town = 'town';
    case Building = 'building';
    case Event = 'event';
}
