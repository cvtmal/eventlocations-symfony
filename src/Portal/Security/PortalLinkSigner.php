<?php

declare(strict_types=1);

namespace App\Portal\Security;

use App\Portal\Entity\PortalUser;
use App\Portal\Repository\PortalUserRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\Signature\Exception\ExpiredSignatureException;
use Symfony\Component\Security\Core\Signature\Exception\InvalidSignatureException;
use Symfony\Component\Security\Core\Signature\SignatureHasher;

/**
 * The signed link of the account mails, `<uuid>.<expires>.<hash>`, without a token table. Registering and a
 * forgotten password and a staff invitation share it and differ only in lifetime. The hash signs the password, so a used link dies by
 * itself; an account with no password signs an empty value, and its link dies once one is set.
 *
 * The shape is checked before any query, which also keeps a malformed value away from the UUID column. The
 * identity is built fresh here: one from the session has no hash, and its reset signature would never match.
 */
final class PortalLinkSigner
{
    private const int REGISTRATION_LIFETIME = 48 * 3600;

    private const int RESET_LIFETIME = 3600;

    /** Staff send it and the recipient did not ask for it, so it waits longer than a registration link. */
    public const int INVITATION_LIFETIME = 7 * 24 * 3600;

    private const string TOKEN = '~^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.(\d{1,12})\.([A-Za-z0-9_\~-]{88})$~D';

    private readonly SignatureHasher $hasher;

    public function __construct(
        PropertyAccessorInterface $propertyAccessor,
        #[Autowire('%kernel.secret%')]
        #[\SensitiveParameter]
        string $secret,
        private readonly PortalUserRepository $users,
    ) {
        $this->hasher = new SignatureHasher($propertyAccessor, ['userIdentifier', 'password'], $secret.'|konto-reset');
    }

    public function registrationToken(PortalUser $user): string
    {
        return $this->resetToken($user, time() + self::REGISTRATION_LIFETIME);
    }

    /**
     * Only for an account with no password: a seven-day link to set one would otherwise be a long reset link.
     * Null for an account that is gone, deactivated or already has a password.
     */
    public function invitationToken(string $portalUserId): ?string
    {
        $user = $this->users->find($portalUserId);

        return null !== $user && $user->isActive() && null === $user->passwordHash() ? $this->resetToken($user, time() + self::INVITATION_LIFETIME) : null;
    }

    public function resetToken(PortalUser $user, ?int $expires = null): string
    {
        $expires ??= time() + self::RESET_LIFETIME;

        return $user->id().'.'.$expires.'.'.$this->hasher->computeSignatureHash(PortalIdentity::fromEntity($user), $expires);
    }

    public function resetUserId(string $token): ?string
    {
        if (1 !== preg_match(self::TOKEN, $token, $parts)) {
            return null;
        }

        $user = $this->users->find($parts[1]);
        if (true !== $user?->isActive()) {
            return null;
        }

        try {
            $this->hasher->verifySignatureHash(PortalIdentity::fromEntity($user), (int) $parts[2], $parts[3]);
        } catch (InvalidSignatureException|ExpiredSignatureException) {
            return null;
        }

        return $user->id();
    }
}
