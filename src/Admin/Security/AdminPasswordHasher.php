<?php

declare(strict_types=1);

namespace App\Admin\Security;

use App\Admin\Exception\AdminUserRuleViolation;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Hashes a staff password with the hasher configured for AdminIdentity, and is the one place that
 * enforces ADM-1's minimum length for the screen, the console and the handlers alike.
 */
final class AdminPasswordHasher
{
    public const int MIN_LENGTH = 12;

    public function __construct(
        private readonly PasswordHasherFactoryInterface $hashers,
    ) {
    }

    /**
     * @throws AdminUserRuleViolation when the password is too short
     */
    public function hash(string $plainPassword): string
    {
        if (mb_strlen($plainPassword) < self::MIN_LENGTH) {
            throw AdminUserRuleViolation::passwordTooShort();
        }

        return $this->hashers->getPasswordHasher(AdminIdentity::class)->hash($plainPassword);
    }
}
