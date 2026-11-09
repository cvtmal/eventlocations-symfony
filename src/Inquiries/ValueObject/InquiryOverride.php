<?php

declare(strict_types=1);

namespace App\Inquiries\ValueObject;

/**
 * The staff mark on an inquiry (D19, G7), stored in `inquiries.qualification_override`. A marked inquiry counts in
 * no qualified figure, no staff alert and no funnel step (D56). Labels: `crm` catalogue, `inquiry.override.<value>`.
 */
enum InquiryOverride: string
{
    case Spam = 'spam';
    case Test = 'test';
}
