<?php

declare(strict_types=1);

namespace App\Factory;

use App\Portal\Entity\PortalVenueAccess;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A pending request of a new manager for a new venue. Needs imported towns, as VenueFactory does,
 * unless a `venue` attribute is passed.
 *
 * @extends PersistentObjectFactory<PortalVenueAccess>
 */
final class PortalVenueAccessFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return PortalVenueAccess::class;
    }

    public function active(): self
    {
        return $this->afterInstantiate(static fn (PortalVenueAccess $access) => $access->confirm(new \DateTimeImmutable()));
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'user' => PortalUserFactory::new(),
            'venue' => VenueFactory::new(),
            'requestedAt' => new \DateTimeImmutable(),
        ];
    }
}
