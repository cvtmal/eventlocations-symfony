<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * No contact row has this id, or not on the Location named (removed, or never existed). Pages answer 404.
 */
final class ContactNotFound extends DomainException
{
    private function __construct(public readonly string $contactId)
    {
        parent::__construct(\sprintf('There is no contact "%s".', $contactId));
    }

    public static function forId(string $contactId): self
    {
        return new self($contactId);
    }
}
