<?php

declare(strict_types=1);

namespace App\Portal\Security;

use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Hashes a manager's password with the hasher configured for PortalIdentity. MIN_LENGTH is the number the
 * forms validate against; the forms own the length, so there is no check here.
 */
final class PortalPasswordHasher
{
    public const int MIN_LENGTH = 8;

    public function __construct(
        private readonly PasswordHasherFactoryInterface $hashers,
    ) {
    }

    public function hash(string $plainPassword): string
    {
        return $this->hashers->getPasswordHasher(PortalIdentity::class)->hash($plainPassword);
    }
}
