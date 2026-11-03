<?php

declare(strict_types=1);

namespace App\Stats\ValueObject;

/**
 * What a contact click on a detail page opened (#47, contract C1), stored in `stats_events.kind`
 * (chk_stats_events_kind). Set exactly when the type is `click` (chk_stats_events_click_kind).
 */
enum ClickKind: string
{
    case Website = 'website';
    case Email = 'email';
    case Phone = 'phone';
    case Map = 'map';
    case Instagram = 'instagram';
}
