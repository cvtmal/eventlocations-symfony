<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * A row of the public tier matrix on `/location-eintragen` (G13), in the order the page shows them. Only features the
 * site has shipped have a case (D18: advertised only when live), so a tier flag for a feature still to come never
 * reaches the page: G15 brought `videos_max` (`Videos`), G14 `last_minute_allowed` (`LastMinute`); G10 adds
 * `detailed_report` with its `tier_page.row.*` key.
 */
enum TierFeature: string
{
    case Promoted = 'promoted';
    case Photos = 'photos';
    case Videos = 'videos';
    case Rooms = 'rooms';
    case Documents = 'documents';
    case Contact = 'contact';
    case EventTypes = 'event_types';
    case LastMinute = 'last_minute';

    /**
     * Whether the cell is a limit (a number, or null for «alle») rather than a yes or no.
     */
    public function isLimit(): bool
    {
        return match ($this) {
            self::Promoted, self::Contact, self::LastMinute => false,
            self::Photos, self::Videos, self::Rooms, self::Documents, self::EventTypes => true,
        };
    }
}
