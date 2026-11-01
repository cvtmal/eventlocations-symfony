<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * Only a contact of the deal's company can go on the deal.
 */
final class ContactOfAnotherCompany extends DomainException
{
    private function __construct(public readonly string $contactId)
    {
        parent::__construct(\sprintf('The contact "%s" belongs to another company.', $contactId));
    }

    public static function forContact(string $contactId): self
    {
        return new self($contactId);
    }
}
