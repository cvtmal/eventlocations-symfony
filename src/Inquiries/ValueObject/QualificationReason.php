<?php

declare(strict_types=1);

namespace App\Inquiries\ValueObject;

/**
 * Why an inquiry is unqualified (G7, D56), stored in `inquiries.qualification_reason`. The cases are in priority
 * order: `QualificationRule` returns the first one that applies. Labels: `crm` catalogue,
 * `inquiry.qualification.reason.<value>`.
 */
enum QualificationReason: string
{
    case NotDelivered = 'not_delivered';
    case Duplicate = 'duplicate';
    case EventTypeNotOffered = 'event_type_not_offered';
    case GuestsOutsideCapacity = 'guests_outside_capacity';
    case DateTooFar = 'date_too_far';
}
