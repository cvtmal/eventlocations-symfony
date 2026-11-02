<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

final readonly class PortalAccountRow
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?string $phone,
        public bool $confirmed,
        public bool $active,
        public ?\DateTimeImmutable $lastLoginAt,
    ) {
    }
}
