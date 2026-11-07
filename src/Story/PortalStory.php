<?php

declare(strict_types=1);

namespace App\Story;

use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Portal\Repository\PortalUserRepository;
use App\Portal\Repository\PortalVenueAccessRepository;
use App\Portal\Security\PortalPasswordHasher;
use App\Venues\Repository\TierRepository;
use App\Venues\Repository\VenueRepository;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\VenueStatus;
use Zenstruck\Foundry\Story;

/**
 * The dev manager, with credentials that are public on purpose, as AdminStory's. Idempotent.
 *
 * The manager looks after two of DemoStory's locations, the first published one of each tier by number, so the plan
 * card of `/konto/locations/{number}` shows both of its states (F12): Free with "Pro anfragen", Pro without it. The
 * story runs after DemoStory; without venues the manager has none, as before.
 */
final class PortalStory extends Story
{
    public const string EMAIL = 'manager@hochzeitslocation.test';
    public const string PASSWORD = 'manager-dev-password';

    public function __construct(
        private readonly PortalUserRepository $users,
        private readonly PortalPasswordHasher $hasher,
        private readonly PortalVenueAccessRepository $access,
        private readonly VenueRepository $venues,
        private readonly TierRepository $tiers,
    ) {
    }

    public function build(): void
    {
        $manager = $this->users->findOneByEmail(self::EMAIL) ?? PortalUserFactory::createOne([
            'email' => self::EMAIL,
            'firstName' => 'Mara',
            'lastName' => 'Manager',
            'passwordHash' => $this->hasher->hash(self::PASSWORD),
        ]);

        foreach ([KnownTiers::FREE, KnownTiers::PRO] as $code) {
            $venue = $this->venues->findOneBy(['tier' => $this->tiers->byCode($code), 'status' => VenueStatus::Published], ['number' => 'ASC']);
            if (null === $venue || null !== $this->access->findAccess($manager->id(), $venue->id())) {
                continue;
            }

            PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);
        }
    }
}
