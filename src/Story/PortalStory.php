<?php

declare(strict_types=1);

namespace App\Story;

use App\Factory\PortalUserFactory;
use App\Portal\Repository\PortalUserRepository;
use App\Portal\Security\PortalPasswordHasher;
use Zenstruck\Foundry\Story;

/**
 * The dev manager, with credentials that are public on purpose, as AdminStory's. Idempotent.
 */
final class PortalStory extends Story
{
    public const string EMAIL = 'manager@hochzeitslocation.test';
    public const string PASSWORD = 'manager-dev-password';

    public function __construct(
        private readonly PortalUserRepository $users,
        private readonly PortalPasswordHasher $hasher,
    ) {
    }

    public function build(): void
    {
        if (null !== $this->users->findOneByEmail(self::EMAIL)) {
            return;
        }

        PortalUserFactory::createOne([
            'email' => self::EMAIL,
            'firstName' => 'Mara',
            'lastName' => 'Manager',
            'passwordHash' => $this->hasher->hash(self::PASSWORD),
        ]);
    }
}
