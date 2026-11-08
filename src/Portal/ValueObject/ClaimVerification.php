<?php

declare(strict_types=1);

namespace App\Portal\ValueObject;

/**
 * The answer of `VerifyClaim` (G6). The account and venue fields are set for a right code only.
 */
final readonly class ClaimVerification
{
    public function __construct(
        public ClaimOutcome $outcome,
        public ?string $portalUserId = null,
        public ?int $venueNumber = null,
        public ?string $venueName = null,
        /** The account has no password yet: the claim flow asks for one before it logs the person in. */
        public bool $needsPassword = false,
        /** Queued because someone else already manages or asked for the venue. */
        public bool $competing = false,
        public int $attemptsLeft = 0,
        public ?string $email = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        /** This verification wrote the pending request, so staff hear of it once. */
        public bool $wroteRequest = false,
    ) {
    }

    public function isVerified(): bool
    {
        return \in_array($this->outcome, [ClaimOutcome::Granted, ClaimOutcome::Queued, ClaimOutcome::AlreadyManager], true);
    }
}
