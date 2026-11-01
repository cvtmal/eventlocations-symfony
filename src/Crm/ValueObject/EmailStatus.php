<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * The outcome of a CRM mail, in an `email_sent` entry's payload. Mail goes out in the request, so
 * there is no `queued`, and `sent` means SMTP accepted it, not that it arrived.
 */
enum EmailStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
}
