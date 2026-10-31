<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * The contact list's sortable columns (`sort=` in the URL), a whitelist like CompanySort.
 */
enum ContactSort: string
{
    case Name = 'name';
    case Kind = 'kind';
    case Company = 'company';
    case Email = 'email';

    public const self DEFAULT = self::Name;

    public function column(): string
    {
        return match ($this) {
            self::Name => "lower(p.last_name || ' ' || coalesce(p.first_name, ''))",
            self::Kind => "array_position(ARRAY['venue_contact', 'couple', 'other']::text[], p.kind::text)",
            self::Company => 'lower(co.name)',
            self::Email => 'p.email',
        };
    }
}
