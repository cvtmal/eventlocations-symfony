<?php

declare(strict_types=1);

namespace App\Portal\Entity;

use App\Portal\Repository\PortalUserRepository;
use App\Shared\Security\PortalActor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A location manager's account in the portal (S16), separate from the staff's AdminUser. The id comes
 * in through the constructor, from EntityId::generate(), so the caller knows it before dispatching.
 * It is a PortalActor because AuditLogEntry's typed association receives this entity's proxy.
 */
#[ORM\Entity(repositoryClass: PortalUserRepository::class)]
#[ORM\Table(name: 'portal_users')]
#[ORM\UniqueConstraint(name: 'uk_portal_users_email', columns: ['email'])]
class PortalUser implements PortalActor
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 180)]
    private string $email;

    /**
     * Null until the mailbox owner chooses one through the mail's link: registering takes no password.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $firstName;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $lastName;

    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $email, string $firstName, string $lastName)
    {
        $this->id = $id;
        $this->email = mb_strtolower($email);
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function passwordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function isConfirmed(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    public function lastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function changePassword(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
        $this->touch();
    }

    /**
     * Holding the mail's link proves the mailbox, so saving through it also confirms the address. A later
     * forgotten password keeps the first time.
     */
    public function setPasswordFromMail(string $passwordHash, \DateTimeImmutable $at): void
    {
        $this->changePassword($passwordHash);
        $this->emailVerifiedAt ??= $at;
    }

    public function updateProfile(string $firstName, string $lastName, ?string $phone): void
    {
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->phone = $phone;
        $this->touch();
    }

    public function deactivate(): void
    {
        $this->isActive = false;
        $this->touch();
    }

    public function reactivate(): void
    {
        $this->isActive = true;
        $this->touch();
    }

    public function recordLogin(\DateTimeImmutable $at): void
    {
        $this->lastLoginAt = $at;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
