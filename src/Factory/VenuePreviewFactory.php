<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\VenuePreview;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A preview link of G5 (`venue_previews`), open for 90 days by default. `expired()` and `revoked()`
 * give the two closed states; `Venues\PreviewToken::forId($preview->id())` builds its token.
 *
 * @extends PersistentObjectFactory<VenuePreview>
 */
final class VenuePreviewFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return VenuePreview::class;
    }

    /**
     * Issued 100 days ago for 30 days.
     */
    public function expired(): self
    {
        return $this->with([
            'at' => new \DateTimeImmutable('-100 days'),
            'expiresAt' => new \DateTimeImmutable('-70 days'),
        ]);
    }

    public function revoked(): self
    {
        return $this->afterInstantiate(static function (VenuePreview $preview): void {
            $preview->revoke(new \DateTimeImmutable());
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'venue' => VenueFactory::new(),
            'campaignCode' => 'demo',
            'expiresAt' => new \DateTimeImmutable('+90 days'),
            'at' => new \DateTimeImmutable(),
        ];
    }
}
