<?php

declare(strict_types=1);

namespace App\Inquiries\ValueObject;

/**
 * The guest's answer to «Hat es geklappt?» in the feedback mail (G16), stored in `inquiry_feedback.outcome`. Never a
 * booking: the venue sees only how many answers came in. Labels: `inquiries` catalogue, `feedback.outcome.<value>`.
 */
enum FeedbackOutcome: string
{
    case Yes = 'yes';
    case No = 'no';
    case Open = 'open';
}
