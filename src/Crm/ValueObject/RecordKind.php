<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * What a timeline entry or a task hangs on: a company or a deal. One enum case per kind of record, so
 * a `match` over it is exhaustive and a new case shows up as a PHPStan error at every place that has to learn it:
 * `recordTable()`, `linkTable()`, `linkColumn()`, `taskColumn()`, the handlers, the routes and the island's `kind` prop.
 */
enum RecordKind: string
{
    case Company = 'company';
    case Deal = 'deal';

    /**
     * The table of the record itself.
     */
    public function recordTable(): string
    {
        return match ($this) {
            self::Company => 'crm_companies',
            self::Deal => 'crm_deals',
        };
    }

    /**
     * The table that joins an activity to this kind of record.
     */
    public function linkTable(): string
    {
        return match ($this) {
            self::Company => 'crm_activity_companies',
            self::Deal => 'crm_activity_deals',
        };
    }

    /**
     * Its column in `linkTable()`.
     */
    public function linkColumn(): string
    {
        return match ($this) {
            self::Company => 'company_id',
            self::Deal => 'deal_id',
        };
    }

    /**
     * Its column in `crm_tasks`.
     */
    public function taskColumn(): string
    {
        return match ($this) {
            self::Company => 'company_id',
            self::Deal => 'deal_id',
        };
    }
}
