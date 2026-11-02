<?php

declare(strict_types=1);

namespace App\Portal\Command;

/**
 * A reset link for an active account. Not audited: nobody is logged in.
 */
final readonly class RequestPortalPasswordReset
{
    public function __construct(
        public string $email,
    ) {
    }
}
