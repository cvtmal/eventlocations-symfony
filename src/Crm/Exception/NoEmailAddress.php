<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * A mail's recipient has no address: the composer offers only those with one, but a stale tab can hold an address
 * that was removed since.
 */
final class NoEmailAddress extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function forCompany(string $companyId): self
    {
        return new self(\sprintf('The company "%s" has no e-mail address.', $companyId));
    }

    public static function forContact(string $contactId): self
    {
        return new self(\sprintf('The contact "%s" has no e-mail address.', $contactId));
    }
}
