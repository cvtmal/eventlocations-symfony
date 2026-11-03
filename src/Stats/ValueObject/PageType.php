<?php

declare(strict_types=1);

namespace App\Stats\ValueObject;

/**
 * The kind of page a beacon came from (#47, contract C1), stored in `stats_events.page`
 * (chk_stats_events_page). An unknown value from the browser becomes Other.
 */
enum PageType: string
{
    case Home = 'home';
    case Listing = 'listing';
    case Landing = 'landing';
    case Detail = 'detail';
    case Merkliste = 'merkliste';
    case Other = 'other';
}
