<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\ValueObject\Outreach;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Creates a Location from one row of the owner's contact list (F14, D49): the Location with its postal address and
 * contact fields, its outreach fields, at most one contact person, and the link to the venue it runs. From
 * `app:crm:import-contacts`; it never sends anything (D30). The ids come from EntityId::generate() before dispatch.
 * The summary names no value.
 */
final readonly class ImportLocation implements AuditableCommand
{
    public function __construct(
        public string $companyId,
        public string $name,
        public ?string $address,
        public ?string $email,
        public ?string $phone,
        public ?string $website,
        /** Null when the row names no contact person. */
        public ?string $contactId,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $position,
        public ?string $contactEmail,
        public ?string $contactPhone,
        public Outreach $outreach,
        public ?int $venueNumber,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('company', $this->companyId);
    }

    public function auditSummary(): array
    {
        return ['import' => true, 'contact' => null !== $this->contactId, 'venue' => null !== $this->venueNumber];
    }
}
