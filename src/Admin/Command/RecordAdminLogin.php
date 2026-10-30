<?php

declare(strict_types=1);

namespace App\Admin\Command;

/**
 * Bookkeeping after a completed login. Not audited: `admin_users.last_login_at` is the record.
 */
final readonly class RecordAdminLogin
{
    public function __construct(
        public string $userId,
    ) {
    }
}
