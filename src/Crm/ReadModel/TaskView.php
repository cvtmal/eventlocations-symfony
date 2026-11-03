<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * The tabs of the task list (CRM-5) in their order, their values the `view` of the URL. Meine, Heute and Überfällig
 * hold open tasks, Heute and Überfällig everyone's, so the two staff can cover for each other; Alle adds the done ones.
 * Alle is first and the default (owner, 2026-11-03).
 */
enum TaskView: string
{
    case All = 'alle';
    case Mine = 'meine';
    case Today = 'heute';
    case Overdue = 'ueberfaellig';

    public const self DEFAULT = self::All;
}
