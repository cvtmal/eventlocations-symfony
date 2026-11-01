<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * The deal list's sortable columns (`sort=` in the URL). The column map is a whitelist, so a URL value never reaches
 * the SQL.
 */
enum DealSort: string
{
    case Name = 'name';
    case Stage = 'stage';
    case Amount = 'amount';
    case Close = 'close';

    public const self DEFAULT = self::Close;

    public function column(): string
    {
        return match ($this) {
            self::Name => 'lower(d.name)',
            self::Stage => 's.position',
            self::Amount => 'd.amount_rappen',
            self::Close => 'd.expected_close_on',
        };
    }
}
