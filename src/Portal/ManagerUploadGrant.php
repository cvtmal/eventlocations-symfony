<?php

declare(strict_types=1);

namespace App\Portal;

use App\Venues\ReadModel\PhotoRightsQuery;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\PhotoGrant;

/**
 * What a manager's new photo is granted (G1), decided in one place for the island's upload and the wizard's
 * form without JavaScript. A photo a manager adds is public at once only when that manager's own declaration is
 * on record for the venue (evidence `upload`: the declaration carries it); before that the photo is stored but
 * waits for the declaration (the Fotos tab or the submit step of the wizard), so nobody publishes a photo
 * without having said they may. Staff add and swap the managers of a venue, so the declaration of one manager
 * never covers another. The grant records the version of the text the manager declared under, not the text in
 * force on the day of the upload. The derivatives choice belongs to the declaration that made it and is not
 * carried over to later uploads.
 */
final class ManagerUploadGrant
{
    public function __construct(
        private readonly PhotoRightsQuery $rights,
    ) {
    }

    public function forUpload(string $portalUserId, int $venueNumber): ?PhotoGrant
    {
        $declaration = $this->rights->declaration($venueNumber, $portalUserId);
        if (null === $declaration) {
            return null;
        }

        return new PhotoGrant(Grantor::manager($portalUserId), GrantEvidence::Upload, policyVersion: $declaration->policyVersion);
    }
}
