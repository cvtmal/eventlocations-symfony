<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * A row of the public tier matrix on `/location-eintragen` (G13), in the order the page shows them. Only features the
 * site has shipped have a case (D18: advertised only when live), so a tier flag for a feature still to come never
 * reaches the page: G15 adds `videos_max`, G14 `last_minute_allowed` and G10 `detailed_report`, each with its
 * `tier_page.row.*` key.
 */
enum TierFeature: string
{
    case Promoted = 'promoted';
    case Photos = 'photos';
    case Rooms = 'rooms';
    case Documents = 'documents';
    case Contact = 'contact';
    case EventTypes = 'event_types';

    /**
     * Whether the cell is a limit (a number, or null for «alle») rather than a yes or no.
     */
    public function isLimit(): bool
    {
        return match ($this) {
            self::Promoted, self::Contact => false,
            self::Photos, self::Rooms, self::Documents, self::EventTypes => true,
        };
    }
}
