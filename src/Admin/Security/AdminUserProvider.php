<?php

declare(strict_types=1);

namespace App\Admin\Security;

use App\Admin\Entity\AdminUser;
use App\Admin\Repository\AdminUserRepository;
use App\Shared\Security\ReviewMode;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Builds the AdminIdentity from the entity and the decrypted secret. A login finds inactive users too, so
 * RefuseInactiveAdminListener can say why they are refused; a refresh does not, so deactivating ends a live
 * session.
 *
 * @implements UserProviderInterface<AdminIdentity>
 */
final class AdminUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly TotpSecretCipher $cipher,
        private readonly ReviewMode $reviewMode,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // review-demo: `admin` stands for the seeded review account, in review mode only.
        $user = $this->users->findOneByEmail($this->reviewMode->adminIdentifier($identifier));
        if (null === $user) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return $this->identity($user);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof AdminIdentity) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $fresh = $this->users->find($user->id());
        if (null === $fresh || !$fresh->isActive()) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($user->getUserIdentifier());

            throw $exception;
        }

        return $this->identity($fresh);
    }

    public function supportsClass(string $class): bool
    {
        return AdminIdentity::class === $class;
    }

    private function identity(AdminUser $user): AdminIdentity
    {
        $encrypted = $user->totpSecretEncrypted();

        return AdminIdentity::fromEntity($user, null === $encrypted ? null : $this->cipher->decrypt($encrypted));
    }
}
