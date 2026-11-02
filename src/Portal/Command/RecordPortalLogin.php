<?php

declare(strict_types=1);

namespace App\Portal\Command;

/**
 * Bookkeeping after a completed login. Not audited: `portal_users.last_login_at` is the record.
 */
final readonly class RecordPortalLogin
{
    public function __construct(
        public string $id,
    ) {
    }
}
