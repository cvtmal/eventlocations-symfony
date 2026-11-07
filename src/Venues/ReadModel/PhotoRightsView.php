<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

/**
 * The rights state of a venue's stored photos (G1), for the "Bildrechte" card of the back office and
 * of `/konto`. `declaredAt` is when the manager the view was read for last gave the declaration for
 * this venue (a grant with evidence `declaration`, live or revoked), null when that manager has none
 * on record, and always null on the staff card, which has no declaration of its own; a manager's
 * later uploads are granted at once only if it is set. One manager's declaration never counts for
 * another.
 */
final readonly class PhotoRightsView
{
    /** How many photos have no live grant: they are stored but not public. */
    public int $ungrantedCount;

    /**
     * @param list<PhotoRightsRow> $photos the gallery first, then each room's photos in room order, each by position
     */
    public function __construct(
        public array $photos,
        public ?\DateTimeImmutable $declaredAt,
    ) {
        $this->ungrantedCount = \count(array_filter($photos, static fn (PhotoRightsRow $photo): bool => !$photo->granted));
    }

    public function hasDeclaration(): bool
    {
        return null !== $this->declaredAt;
    }
}
