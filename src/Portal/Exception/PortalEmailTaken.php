<?php

declare(strict_types=1);

namespace App\Portal\Exception;

use App\Shared\Exception\DomainException;

/**
 * Two writes with one address (registering, or staff inviting) raced past the lookup and `uk_portal_users_email` stopped the second.
 * On registration the controller answers as usual, so a known address still looks like a new one from outside;
 * on an invitation staff get a flash and can add the address again.
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

    public static function onInvitation(): self
    {
        return new self('This e-mail address got an account while staff invited it.');
    }
}
