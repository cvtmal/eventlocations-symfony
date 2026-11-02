<?php

declare(strict_types=1);

namespace App\Portal\Exception;

use App\Shared\Exception\DomainException;

/**
 * Two registrations with one address raced past the lookup and `uk_portal_users_email` stopped the second.
 * The controller answers as usual, so a known address still looks like a new one from outside.
 */
final class PortalEmailTaken extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function onRegistration(): self
    {
        return new self('This e-mail address already has an account.');
    }
}
