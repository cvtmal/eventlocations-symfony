<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * Whether a venue is listed. An unpublished venue answers 410 on its detail page (#8)
 * and is what the content import's --prune leaves behind (#9). Stored in `venues.status`
 * (chk_venues_status); never shown, so it has no catalogue label.
 */
enum VenueStatus: string
{
    case Published = 'published';
    case Unpublished = 'unpublished';
}
