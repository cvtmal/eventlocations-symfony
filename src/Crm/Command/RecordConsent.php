<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Records on a Location's timeline that a hochzeitslocation.ch customer said yes to the import of their entry (F14,
 * D29): from `app:crm:record-consents`, which reads the consent columns of the venue sheet. The entry is the proof, so
 * nobody can edit it; the Location's basis of contact becomes "consent". The summary names no address.
 */
final readonly class RecordConsent implements AuditableCommand
{
    public const string SOURCE_HOCHZEITSLOCATION = 'hochzeitslocation';

    public function __construct(
        public string $companyId,
        public string $email,
        /** The day of the yes, `Y-m-d`. */
        public string $consentOn,
        public string $source = self::SOURCE_HOCHZEITSLOCATION,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('company', $this->companyId);
    }

    public function auditSummary(): array
    {
        return ['consent' => 'recorded'];
    }
}
