<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * The tabs of the task list (CRM-5), their values the `view` of the URL. Meine, Heute and Überfällig hold open
 * tasks, Heute and Überfällig everyone's, so the two staff can cover for each other; Alle adds the done ones.
 */
enum TaskView: string
{
    case Mine = 'meine';
    case Today = 'heute';
    case Overdue = 'ueberfaellig';
    case All = 'alle';

    public const self DEFAULT = self::Mine;
}
