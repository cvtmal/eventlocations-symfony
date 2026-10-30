<?php

declare(strict_types=1);

namespace App\Admin\ReadModel;

use App\Admin\ValueObject\AdminRole;

final readonly class AdminUserRow
{
    public function __construct(
        public string $id,
        public string $displayName,
        public string $email,
        public AdminRole $role,
        public bool $totpEnrolled,
        public bool $active,
        public ?\DateTimeImmutable $lastLoginAt,
    ) {
    }
}
