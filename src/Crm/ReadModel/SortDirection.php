<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * `dir=` of #23's sortable `_table` headers on the CRM lists (Crm may not use Cms's twin).
 */
enum SortDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';

    public function sql(): string
    {
        return self::Asc === $this ? 'ASC' : 'DESC';
    }
}
