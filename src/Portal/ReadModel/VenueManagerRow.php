<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

final readonly class VenueManagerRow
{
    public function __construct(
        public string $userId,
        public string $firstName,
        public string $lastName,
        public string $email,
    ) {
    }
}
