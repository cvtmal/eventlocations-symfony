<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

/**
 * `dir=` of #23's sortable `_table` headers.
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
