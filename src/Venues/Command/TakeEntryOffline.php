<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Staff take the entry of a complaint offline until they decide (G2): a credible removal or rights complaint about
 * the whole entry. It nests `UnpublishVenue` (its own audit row and purge) and records on the case that it did, so
 * a `keep` decision puts the entry back online. An entry that is already offline stays as it is, and the case
 * records nothing: the answer says whether this took it offline. `by` is the admin user's id.
 */
final readonly class TakeEntryOffline implements AuditableCommand
{
    public function __construct(
        public string $caseId,
        public string $by,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('moderation_case', $this->caseId);
    }

    public function auditSummary(): array
    {
        return ['entry' => 'offline'];
    }
}
