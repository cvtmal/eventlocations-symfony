<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * Another contact has this e-mail address (uk_crm_contacts_email, case-insensitive through
 * ContactEmail). The message never quotes the address. The API answers 422 on `value`, a form 422
 * on its e-mail field.
 */
final class ContactEmailTaken extends DomainException
{
    private function __construct()
    {
        parent::__construct('Another contact has this e-mail address.');
    }

    public static function create(): self
    {
        return new self();
    }
}
