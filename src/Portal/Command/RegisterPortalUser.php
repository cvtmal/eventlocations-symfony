<?php

declare(strict_types=1);

namespace App\Portal\Command;

/**
 * A new account, or the link once more for one with no password yet. Not audited: nobody is logged in.
 */
final readonly class RegisterPortalUser
{
    public function __construct(
        public string $id,
        public string $email,
        public string $firstName,
        public string $lastName,
    ) {
    }
}
