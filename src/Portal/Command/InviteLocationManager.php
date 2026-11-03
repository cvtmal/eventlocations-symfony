<?php

declare(strict_types=1);

namespace App\Portal\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Staff add a location manager by an address that has no account yet: the account is created with no password
 * and an `active` access row, and the controller mails the invitation after the commit. The link is the one of
 * registering, so only the mailbox owner can set the password.
 */
final readonly class InviteLocationManager implements AuditableCommand
{
    public function __construct(
        public string $portalUserId,
        public string $email,
        public string $firstName,
        public string $lastName,
        public string $venueId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('portal_user', $this->portalUserId);
    }

    public function auditSummary(): array
    {
        return ['email' => $this->email, 'first_name' => $this->firstName, 'last_name' => $this->lastName, 'venue' => $this->venueId, 'via' => GrantVenueAccess::VIA_PANEL];
    }
}
