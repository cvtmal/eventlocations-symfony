<?php

declare(strict_types=1);

namespace App\Portal\Command;

/**
 * G6: a claimant types the mailed code. The handler answers a `ClaimVerification`; a wrong code is counted, not
 * thrown, so the count survives the transaction.
 */
final readonly class VerifyClaim
{
    public function __construct(
        public string $challengeId,
        public string $code,
    ) {
    }
}
