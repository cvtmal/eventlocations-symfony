<?php

declare(strict_types=1);

namespace App\Portal\Security;

use App\Portal\Repository\PortalUserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Builds the PortalIdentity from the entity. A login finds inactive users too, so RefusePortalLoginListener
 * can say why they are refused; a refresh does not, so deactivating ends a live session.
 *
 * @implements UserProviderInterface<PortalIdentity>
 */
final class PortalUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly PortalUserRepository $users,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->users->findOneByEmail($identifier);
        if (null === $user) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return PortalIdentity::fromEntity($user);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof PortalIdentity) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $fresh = $this->users->find($user->id());
        if (null === $fresh || !$fresh->isActive()) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($user->getUserIdentifier());

            throw $exception;
        }

        return PortalIdentity::fromEntity($fresh);
    }

    public function supportsClass(string $class): bool
    {
        return PortalIdentity::class === $class;
    }
}
