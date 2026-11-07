<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * What a grant rests on (G1), stored in `media_rights_grants.evidence`
 * (`chk_media_rights_grants_evidence`):
 *
 * - `declaration`: the manager's policy-versioned declaration (the box of the submit step, or the
 *   declaration form of the Fotos tab), covering every photo that had no grant yet;
 * - `upload`: a photo a manager added after a declaration was on record; the upload carries it;
 * - `staff_attestation`: staff attest the rights (a CMS upload, "Alle aktuellen Fotos freigeben",
 *   the import of the owner's own folders). The venue confirms at claim time.
 */
enum GrantEvidence: string
{
    case Declaration = 'declaration';
    case Upload = 'upload';
    case StaffAttestation = 'staff_attestation';
}
