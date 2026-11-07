<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * How a new photo is granted at the moment it is created (G1): who grants it, on what evidence,
 * and whether promotional derivatives are allowed too (parked, but the choice is recorded).
 * `AddVenuePhoto` carries one when the caller can vouch for the file; without one the photo stays
 * private until a `GrantPhotoRights` covers it.
 *
 * `policyVersion` names the text the grantor agreed to when that is not the text in force today:
 * an upload covered by a manager's earlier declaration carries the version of that declaration.
 * Null means the text in force.
 */
final readonly class PhotoGrant
{
    public function __construct(
        public Grantor $grantor,
        public GrantEvidence $evidence,
        public bool $derivatives = false,
        public ?string $policyVersion = null,
    ) {
    }
}
