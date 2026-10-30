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
    /** Kept as it is because the CMS edited or deleted the venue since its last import (#24, S8). */
    case CmsEdited = 'cms_edited';
}
