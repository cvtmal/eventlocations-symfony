<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;
use App\Venues\ValueObject\ModerationDecision;

/**
 * Staff decide a complaint (G2); a decided case is never decided again (`ModerationCaseAlreadyDecided`).
 *
 * - `remove`: every named photo that still exists is revoked through G1's `RemoveVenuePhoto` (grant ended with
 *   staff as the revoker, photo and files deleted, venue and listings purged). An entry the case took offline
 *   stays offline.
 * - `keep`: the hold lifts (it is the open case), the venue and the listings are purged, and an entry the case
 *   took offline goes back online through `PublishVenue`, which records the funnel step `venue_published` like
 *   every republish (G8).
 *
 * `note` is staff's optional reason, kept on the case and out of the audit summary. `by` is the admin user's id.
 */
final readonly class DecideModerationCase implements AuditableCommand
{
    public function __construct(
        public string $caseId,
        public ModerationDecision $decision,
        public ?string $note,
        public string $by,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('moderation_case', $this->caseId);
    }

    public function auditSummary(): array
    {
        return ['decision' => $this->decision->value];
    }
}
