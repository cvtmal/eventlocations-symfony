<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

/**
 * The account behind an address staff typed on the managers panel. `invited` is an account with no password yet:
 * invited by staff, or registered and never finished; either way the invitation mail is the one to send.
 */
final readonly class ManagerAccountRow
{
    public function __construct(
        public string $id,
        public string $email,
        public string $firstName,
        public string $lastName,
        public ?string $phone,
        public bool $active,
        public bool $invited,
    ) {
    }
}
