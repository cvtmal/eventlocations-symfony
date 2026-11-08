<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * G6: the claimant's decision on the website import's photo candidates. `approvedIds` are the ones that became
 * photos of the venue, `rejectedIds` the ones left out; an id of another venue's candidate is ignored, so a crafted
 * form changes nothing elsewhere.
 */
final readonly class DecidePhotoCandidates implements AuditableCommand
{
    /**
     * @param list<string> $approvedIds
     * @param list<string> $rejectedIds
     */
    public function __construct(
        public int $venueNumber,
        public array $approvedIds,
        public array $rejectedIds,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['approved' => \count($this->approvedIds), 'rejected' => \count($this->rejectedIds)];
    }
}
