<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

final readonly class PortalAccessRequestRow
{
    public function __construct(
        public string $userId,
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $phone,
        public int $venueNumber,
        public string $venueName,
        public \DateTimeImmutable $requestedAt,
    ) {
    }
}
