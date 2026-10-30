<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * What app:venues:import did (or would do, in a dry run) with one content folder.
 */
enum ImportStatus: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Failed = 'failed';
}
