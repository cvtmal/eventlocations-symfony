<?php

declare(strict_types=1);

namespace App\Admin\Security;

use App\Admin\Entity\AdminUser;
use App\Admin\ValueObject\AdminRole;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The logged-in staff member, kept apart from the AdminUser entity: the entity cannot decrypt the TOTP
 * secret, and the Doctrine graph stays out of the session. AdminUserProvider builds it on every request.
 *
 * Only id, e-mail, name, role and two fingerprints reach the session, never the hash or the secret. The
 * fingerprints change with the password and the stored secret, so isEqualTo() fails against the fresh
 * object after a password reset, a role change or a 2FA reset, and Symfony ends that session.
 */
final class AdminIdentity implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, EquatableInterface
{
    private const int TOTP_PERIOD = 30;
    private const int TOTP_DIGITS = 6;

    private function __construct(
        private string $id,
        private string $email,
        private string $displayName,
        private AdminRole $role,
        private bool $active,
        private ?string $passwordHash,
        private ?string $totpSecret,
        private string $passwordFingerprint,
        private string $totpFingerprint,
    ) {
    }

    public static function fromEntity(AdminUser $user, ?string $totpSecret): self
    {
        return new self(
            $user->id(),
            $user->email(),
            $user->displayName(),
            $user->role(),
            $user->isActive(),
            $user->passwordHash(),
            $totpSecret,
            hash('crc32c', $user->passwordHash()),
            hash('crc32c', $user->totpSecretEncrypted() ?? ''),
        );
    }

    /**
     * The same user with a pending secret, to build the provisioning URI and check the first code.
     */
    public function withTotpSecret(string $secret): self
    {
        $identity = clone $this;
        $identity->totpSecret = $secret;

        return $identity;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function firstName(): string
    {
        return explode(' ', trim($this->displayName), 2)[0];
    }

    /**
     * `AK` for "Andrea Keller", for the avatar in the sidebar.
     */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->displayName)) ?: [];
        $first = $words[0] ?? '';
        $last = \count($words) > 1 ? $words[\count($words) - 1] : '';

        return mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1));
    }

    public function role(): AdminRole
    {
        return $this->role;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getRoles(): array
    {
        return [$this->role->securityRole()];
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    #[\Deprecated('The identity holds no plain credentials.')]
    public function eraseCredentials(): void
    {
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return null !== $this->totpSecret;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if (null === $this->totpSecret) {
            return null;
        }

        return new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, self::TOTP_PERIOD, self::TOTP_DIGITS);
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->id === $this->id
            && $user->email === $this->email
            && $user->role === $this->role
            && $user->passwordFingerprint === $this->passwordFingerprint
            && $user->totpFingerprint === $this->totpFingerprint;
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'displayName' => $this->displayName,
            'role' => $this->role->value,
            'passwordFingerprint' => $this->passwordFingerprint,
            'totpFingerprint' => $this->totpFingerprint,
        ];
    }

    /**
     * @param array{id: string, email: string, displayName: string, role: string, passwordFingerprint: string, totpFingerprint: string} $data
     */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->email = $data['email'];
        $this->displayName = $data['displayName'];
        $this->role = AdminRole::from($data['role']);
        $this->active = true;
        $this->passwordHash = null;
        $this->totpSecret = null;
        $this->passwordFingerprint = $data['passwordFingerprint'];
        $this->totpFingerprint = $data['totpFingerprint'];
    }
}
