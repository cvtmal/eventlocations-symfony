<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\MediaRightsGrant;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\lazy;

/**
 * A live rights grant (G1): system, staff attestation, the `legacy` policy, no derivatives. Rows
 * only, like the photo factory. `VenuePhotoFactory` makes one for every photo it creates, so a
 * factory photo is public; `revoked()` makes a grant that is already ended when it is stored.
 *
 * @extends PersistentObjectFactory<MediaRightsGrant>
 */
final class MediaRightsGrantFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return MediaRightsGrant::class;
    }

    /**
     * Ended before the INSERT, not after it: stored live first, a revoked grant would collide with
     * the live grant of a photo that has one (`uk_media_rights_grants_live_photo`).
     */
    public function revoked(?Grantor $by = null): self
    {
        return $this->afterInstantiate(static function (MediaRightsGrant $grant) use ($by): void {
            $grant->revoke($by ?? Grantor::system('test'), new \DateTimeImmutable());
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'photo' => VenuePhotoFactory::new()->withoutGrant(),
            'grantor' => Grantor::system('demo'),
            'evidence' => GrantEvidence::StaffAttestation,
            'policy' => lazy(static fn () => RightsPolicyVersionFactory::legacy()),
            'derivatives' => false,
            'at' => new \DateTimeImmutable(),
        ];
    }
}
