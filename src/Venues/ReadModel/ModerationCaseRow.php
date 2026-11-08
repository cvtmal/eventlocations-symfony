<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Venues\ValueObject\ModerationDecision;
use App\Venues\ValueObject\ModerationKind;
use App\Venues\ValueObject\ModerationStatus;

/**
 * One complaint in the staff queue (G2). The venue is null when the complaint named none of the site (then
 * `venueReference` is what was typed) or the venue was deleted since. `heldPhotos` counts the photos the case hides
 * right now: the named photos that still exist while it is open, 0 once decided.
 */
final readonly class ModerationCaseRow
{
    public function __construct(
        public string $id,
        public \DateTimeImmutable $createdAt,
        public ModerationKind $kind,
        public ModerationStatus $status,
        public ?ModerationDecision $decision,
        public ?\DateTimeImmutable $decidedAt,
        public ?int $venueNumber,
        public ?string $venueName,
        public ?string $venueReference,
        public ?string $complainantName,
        public int $heldPhotos,
    ) {
    }

    public function isOpen(): bool
    {
        return ModerationStatus::Open === $this->status;
    }
}
