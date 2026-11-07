<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Venues\ValueObject\ModerationDecision;
use App\Venues\ValueObject\ModerationKind;
use App\Venues\ValueObject\ModerationStatus;

/**
 * Everything staff and the two mails need of one complaint (G2). The venue fields are null when the case names no
 * venue of the site; `venuePublished` is the venue's status now. `decidedBy` is the admin user's id.
 */
final readonly class ModerationCaseView
{
    /**
     * @param list<ModerationCasePhotoView> $photos in the order they were named
     */
    public function __construct(
        public string $id,
        public ModerationKind $kind,
        public ModerationStatus $status,
        public ?int $venueNumber,
        public ?string $venueName,
        public bool $venuePublished,
        public ?string $venueReference,
        public string $complainantName,
        public string $complainantEmail,
        public string $message,
        public \DateTimeImmutable $goodFaithAt,
        public ?string $sourceUrl,
        public \DateTimeImmutable $createdAt,
        public ?ModerationDecision $decision,
        public ?string $decisionNote,
        public ?\DateTimeImmutable $decidedAt,
        public ?string $decidedBy,
        public bool $tookEntryOffline,
        public array $photos,
    ) {
    }

    public function isOpen(): bool
    {
        return ModerationStatus::Open === $this->status;
    }

    /**
     * Staff may take the entry offline while the case is open and the venue is online.
     */
    public function canTakeEntryOffline(): bool
    {
        return $this->isOpen() && null !== $this->venueNumber && $this->venuePublished;
    }

    /**
     * How many named photos the case hides right now.
     */
    public function heldPhotos(): int
    {
        return $this->isOpen() ? \count(array_filter($this->photos, static fn (ModerationCasePhotoView $photo): bool => $photo->exists())) : 0;
    }
}
