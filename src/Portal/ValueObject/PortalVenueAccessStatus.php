<?php

declare(strict_types=1);

namespace App\Portal\ValueObject;

/**
 * State of a manager's access to a location, stored in `portal_venue_access.status`
 * (chk_portal_venue_access_status). A rejected request deletes the row, so it has no case here.
 */
enum PortalVenueAccessStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
}
