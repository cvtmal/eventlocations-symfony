<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * A line of an imported customer's note (#48 R8): a sheet column with no CRM field, or a cell the import
 * dropped. The cases are in the order the lines appear. The label is `crm` key `customer_import.note.<value>`.
 */
enum CustomerNoteField: string
{
    case Status = 'status';
    case Salutation = 'salutation';
    case Region = 'region';
    case Instagram = 'instagram';
    case Note = 'note';
    case Email = 'email';
    case Phone = 'phone';
    case Website = 'website';
    case MailSent = 'mail_sent';

    public function key(): string
    {
        return 'customer_import.note.'.$this->value;
    }
}
