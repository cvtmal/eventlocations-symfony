<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

final readonly class PortalProfileRow
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public ?string $phone,
        public string $email,
        public bool $active,
    ) {
    }
}
