<?php

declare(strict_types=1);

namespace App\Portal\Command;

/**
 * G6: a claimant asks for a code to prove an address. Not audited: nobody is logged in. The handler answers the
 * plain code, which only the mail carries; `id` is the new challenge's id.
 */
final readonly class StartClaim
{
    public function __construct(
        public string $id,
        public string $venueId,
        public string $email,
        public string $firstName,
        public string $lastName,
        public ?string $campaignCode = null,
    ) {
    }
}
