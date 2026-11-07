<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Entity\MediaRightsGrant;
use App\Venues\Entity\RightsPolicyVersion;
use App\Venues\Entity\VenuePhoto;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\PhotoGrant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The one way a grant is written or ended (G1). Every path that makes a photo public composes it:
 * `AddVenuePhoto` (staff and manager uploads), the content import, `GrantPhotoRights`; and
 * `RemoveVenuePhoto` ends the live grant before the photo goes. It persists but never flushes,
 * and it purges nothing: the command that calls it owns the transaction and the cache purge
 * (`VenueEditing::close()`).
 */
final class PhotoGrants
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RightsPolicy $policy,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Grants a photo under the declaration text in force, or under the version the grant names: an
     * upload that a manager's earlier declaration covers records the words that manager saw, even
     * after staff edited the text.
     */
    public function issue(VenuePhoto $photo, PhotoGrant $how): MediaRightsGrant
    {
        // A photo the import has built but not persisted yet must be known before a grant points at it.
        $this->em->persist($photo);
        $grant = new MediaRightsGrant(
            $photo,
            $how->grantor,
            $how->evidence,
            $this->policyOf($how),
            $how->derivatives,
            $this->clock->now(),
        );
        $this->em->persist($grant);

        return $grant;
    }

    /**
     * Before a photo row is removed: ends its live grants and lets every grant of the photo go
     * (the rows stay as evidence; the database sets their photo to NULL, and the unit of work must
     * not still point at the removed entity).
     *
     * @return int how many grants were live
     */
    public function releasePhoto(VenuePhoto $photo, Grantor $by): int
    {
        $grants = $this->em->getRepository(MediaRightsGrant::class)->findBy(['photo' => $photo]);
        $now = $this->clock->now();
        $live = 0;
        foreach ($grants as $grant) {
            $live += $grant->isLive() ? 1 : 0;
            $grant->revoke($by, $now);
            $grant->detach();
        }

        return $live;
    }

    /**
     * The row of the version the grant names. A named version is always a row (a grant's `policy_version`
     * is a foreign key); should it be missing all the same, the text in force stands in, so the photo is
     * not lost over a gap in the record.
     */
    private function policyOf(PhotoGrant $how): RightsPolicyVersion
    {
        $named = null === $how->policyVersion ? null : $this->em->find(RightsPolicyVersion::class, $how->policyVersion);

        return $named ?? $this->policy->record($this->policy->current());
    }
}
