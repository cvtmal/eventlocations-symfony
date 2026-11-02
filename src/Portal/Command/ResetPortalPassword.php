<?php

declare(strict_types=1);

namespace App\Portal\Command;

/**
 * The new password from a verified reset link. Not audited: nobody is logged in.
 */
final readonly class ResetPortalPassword
{
    public function __construct(
        public string $id,
        public string $plainPassword,
    ) {
    }
}
