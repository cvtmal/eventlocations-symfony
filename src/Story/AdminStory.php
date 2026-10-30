<?php

declare(strict_types=1);

namespace App\Story;

use App\Admin\Repository\AdminUserRepository;
use App\Admin\Security\AdminPasswordHasher;
use App\Admin\Security\TotpSecretCipher;
use App\Admin\ValueObject\AdminRole;
use App\Factory\AdminUserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Story;

/**
 * The dev admin, with credentials that are public on purpose (`CLAUDE.md` ### Admin). Idempotent.
 */
final class AdminStory extends Story
{
    public const string EMAIL = 'admin@hochzeitslocation.test';
    public const string PASSWORD = 'admin-dev-password';
    public const string TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly AdminPasswordHasher $hasher,
        private readonly TotpSecretCipher $cipher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function build(): void
    {
        if (null !== $this->users->findOneByEmail(self::EMAIL)) {
            return;
        }

        $admin = AdminUserFactory::createOne([
            'email' => self::EMAIL,
            'displayName' => 'Andrea Admin',
            'passwordHash' => $this->hasher->hash(self::PASSWORD),
            'role' => AdminRole::Admin,
        ]);
        $admin->enrolTotp($this->cipher->encrypt(self::TOTP_SECRET));
        $this->em->flush();
    }
}
