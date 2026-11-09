<?php

declare(strict_types=1);

namespace App\Inquiries\ValueObject;

/**
 * The derived D19 flag of an inquiry, stored in `inquiries.qualification` at each delivery (G7, D56). Null on the
 * row while the inquiry has not been delivered yet. Labels: `crm` catalogue, `inquiry.qualification.<value>`.
 */
enum Qualification: string
{
    case Qualified = 'qualified';
    case Unqualified = 'unqualified';
}
