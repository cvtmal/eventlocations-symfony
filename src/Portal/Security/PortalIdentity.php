<?php

declare(strict_types=1);

namespace App\Portal\Security;

use App\Portal\Entity\PortalUser;
use App\Shared\Security\PortalActor;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The logged-in manager, kept apart from the PortalUser entity so the Doctrine graph stays out of the
 * session. PortalUserProvider builds it on every request.
 *
 * Only id, e-mail, names and a fingerprint reach the session, never the hash. The fingerprint changes with
 * the password, so isEqualTo() fails against the fresh object after a password change, and Symfony ends
 * that session.
 */
final class PortalIdentity implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface, PortalActor
{
    private function __construct(
        private string $id,
        private string $email,
        private string $firstName,
        private string $lastName,
        private bool $active,
        private ?string $passwordHash,
        private string $passwordFingerprint,
    ) {
    }

    public static function fromEntity(PortalUser $user): self
    {
        return new self(
            $user->id(),
            $user->email(),
            $user->firstName(),
            $user->lastName(),
            $user->isActive(),
            $user->passwordHash(),
            hash('crc32c', $user->passwordHash() ?? ''),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function displayName(): string
    {
        return $this->firstName.' '.$this->lastName;
    }

    /**
     * `AK` for "Andrea Keller", for the avatar in the sidebar.
     */
    public function initials(): string
    {
        return mb_strtoupper(mb_substr($this->firstName, 0, 1).mb_substr($this->lastName, 0, 1));
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getRoles(): array
    {
        return ['ROLE_PORTAL_USER'];
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

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->id === $this->id
            && $user->email === $this->email
            && $user->passwordFingerprint === $this->passwordFingerprint;
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'passwordFingerprint' => $this->passwordFingerprint,
        ];
    }

    /**
     * @param array{id: string, email: string, firstName: string, lastName: string, passwordFingerprint: string} $data
     */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->email = $data['email'];
        $this->firstName = $data['firstName'];
        $this->lastName = $data['lastName'];
        $this->active = true;
        $this->passwordHash = null;
        $this->passwordFingerprint = $data['passwordFingerprint'];
    }
}
