<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;

/**
 * Grants the photos of a venue that have no live grant yet (G1): the staff action "Alle aktuellen
 * Fotos freigeben", a manager's declaration on the Fotos tab or at the submit step, and G6's
 * claim step, which names the photos the claimant ticked. `photoIds` null means every photo of the
 * venue (gallery and rooms) without a live grant; a photo that already has one is left alone, and
 * an id of another venue is a `VenueRowNotFound`. Answers how many photos were granted.
 *
 * It never revives a revoked grant: a revoked photo is gone (RemoveVenuePhoto).
 */
final readonly class GrantPhotoRights implements AuditableCommand
{
    /**
     * @param list<string>|null $photoIds
     */
    public function __construct(
        public int $venueNumber,
        public Grantor $grantor,
        public GrantEvidence $evidence,
        public bool $derivatives = false,
        public ?array $photoIds = null,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return [
            'grantor' => $this->grantor->type->value,
            'evidence' => $this->evidence->value,
            'derivatives' => $this->derivatives,
            'photos' => null === $this->photoIds ? 'all' : \count($this->photoIds),
        ];
    }
}
