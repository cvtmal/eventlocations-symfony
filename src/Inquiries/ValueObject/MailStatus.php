<?php

declare(strict_types=1);

namespace App\Inquiries\ValueObject;

/**
 * The outcome of one mail of an inquiry (IQ-1), stored in `venue_mail_status` and
 * `guest_mail_status`. `skipped`: the guest copy was not attempted because the venue mail failed.
 */
enum MailStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
