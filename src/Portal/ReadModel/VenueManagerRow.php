<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

/**
 * `invited`: the account has no password yet, so the panel shows "Einladung offen" and a resend button.
 */
final readonly class VenueManagerRow
{
    public function __construct(
        public string $userId,
        public string $firstName,
        public string $lastName,
        public string $email,
        public bool $invited,
    ) {
    }
}
