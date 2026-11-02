<?php

declare(strict_types=1);

namespace App\Portal\ValueObject;

/**
 * Where the review of a manager's location stands (#45). Whether the location is online stays in `venues.status`.
 */
enum SubmissionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Rejected = 'rejected';
    case Accepted = 'accepted';
}
