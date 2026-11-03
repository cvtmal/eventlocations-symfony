<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * What the customer import does with a row of the sheet (#48 research §7, R4–R6). Only `New` is written;
 * every other class is skipped and listed in the report.
 */
enum CustomerRowClass: string
{
    case New = 'new';
    case MatchedVenue = 'matched (venue)';
    case MatchedName = 'matched (name)';
    case DuplicateInSheet = 'duplicate in sheet';
    case NameRepeated = 'name repeated';
    case Ambiguous = 'ambiguous';
    case Check = 'to check';
    case Blocked = 'blocked';
    case Invalid = 'invalid';
}
