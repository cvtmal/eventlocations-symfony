<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

final readonly class VenueContactFacts
{
    public function __construct(
        public string $id,
        public int $number,
        public string $name,
        public ?string $website,
        public ?string $phone,
        public ?string $inquiryEmail,
        public ?string $contactPerson,
        public ?string $street,
        public ?string $zip,
        public ?string $townName,
    ) {
    }
}
