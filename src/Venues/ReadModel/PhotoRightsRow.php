<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\GrantorType;

/**
 * One stored photo and its rights state, as the "Bildrechte" card shows it (G1). `granted` means a live
 * grant exists; the grant's facts (`evidence`, `grantorType`, `grantedAt`, `policyVersion`) are null
 * without one, and `derivatives` is then false. `roomName` is null for a gallery photo. `held` means an open
 * complaint names the photo (G2): it is hidden from the public until staff decide, granted or not.
 */
final readonly class PhotoRightsRow
{
    public function __construct(
        public string $photoId,
        public int $position,
        public ?string $roomName,
        public bool $granted,
        public ?GrantEvidence $evidence,
        public ?GrantorType $grantorType,
        public ?\DateTimeImmutable $grantedAt,
        public bool $derivatives,
        public ?string $policyVersion,
        public bool $held = false,
    ) {
    }
}
