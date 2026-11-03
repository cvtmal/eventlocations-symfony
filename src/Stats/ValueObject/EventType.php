<?php

declare(strict_types=1);

namespace App\Stats\ValueObject;

/**
 * The type of a raw event (#47, contract C2), stored in `stats_events.type` (chk_stats_events_type).
 */
enum EventType: string
{
    case PageView = 'page_view';
    case Impression = 'impression';
    case Click = 'click';
    case MerklisteAdd = 'merkliste_add';
    case Inquiry = 'inquiry';
}
