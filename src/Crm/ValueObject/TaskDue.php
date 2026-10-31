<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * Where a due date stands against today, the three pills of the design (`Main.dc.html`). Both days are
 * `YYYY-MM-DD`, so they compare as strings.
 */
enum TaskDue: string
{
    case Overdue = 'overdue';
    case Today = 'today';
    case Later = 'later';

    public static function of(string $dueOn, string $today): self
    {
        return match (true) {
            $dueOn < $today => self::Overdue,
            $dueOn === $today => self::Today,
            default => self::Later,
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Overdue => 'amber',
            self::Today => 'blue',
            self::Later => 'grey',
        };
    }
}
