<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * The company list's sortable columns (`sort=` in the URL). The column map is a whitelist, so a URL
 * value never reaches the SQL.
 */
enum CompanySort: string
{
    case Name = 'name';
    case Stage = 'stage';
    case Venues = 'venues';
    case Created = 'created';

    public const self DEFAULT = self::Name;

    public function column(): string
    {
        return match ($this) {
            self::Name => 'lower(c.name)',
            // The stages in their lifecycle order, not alphabetically.
            self::Stage => "array_position(ARRAY['prospect', 'customer', 'former_customer']::text[], c.stage::text)",
            // The Eintrag's name; the direction applies to the name only, so Locations without one stay last both ways.
            self::Venues => '(ev.id IS NULL), lower(ev.name)',
            self::Created => 'c.created_at',
        };
    }
}
