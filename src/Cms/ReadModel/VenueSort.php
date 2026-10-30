<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

/**
 * The venue list's sortable columns (`sort=` in the URL). The column map is a whitelist, so a URL value
 * never reaches the SQL.
 */
enum VenueSort: string
{
    case Name = 'name';
    case Tier = 'tier';
    case Capacity = 'capacity';
    case Updated = 'updated';

    public const self DEFAULT = self::Name;

    public function column(): string
    {
        return match ($this) {
            self::Name => 'v.name',
            self::Tier => 'v.tier_rank',
            self::Capacity => 'v.capacity_max',
            self::Updated => 'v.updated_at',
        };
    }
}
