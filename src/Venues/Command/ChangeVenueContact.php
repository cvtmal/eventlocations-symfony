<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * The CMS card "Kontakt" (#24). The audit summary names the changed fields only: contact
 * data never goes into the append-only log (D11).
 */
final readonly class ChangeVenueContact implements AuditableCommand
{
    /**
     * @param list<string> $changedFields
     * @param string|null  $phone         E.164
     */
    public function __construct(
        public int $venueNumber,
        public string $loadedAt,
        public array $changedFields,
        public ?string $phone,
        public ?string $inquiryEmail,
        public ?string $website,
        public ?string $contactPerson,
        public ?string $instagram,
        public bool $acceptsMultiInquiries,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['changed' => $this->changedFields];
    }
}
