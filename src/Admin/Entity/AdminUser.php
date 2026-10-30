<?php

declare(strict_types=1);

namespace App\Admin\Entity;

use App\Admin\Repository\AdminUserRepository;
use App\Admin\ValueObject\AdminRole;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A staff account of the back office (S12). The id comes in through the constructor so the caller
 * knows it before dispatching (AuditableCommand::auditSubject()); it still comes only from
 * EntityId::generate(). The TOTP secret is stored encrypted (TotpSecretCipher) and is NULL until the
 * user has enrolled. Accounts are deactivated, never deleted: the audit log references them.
 */
#[ORM\Entity(repositoryClass: AdminUserRepository::class)]
#[ORM\Table(name: 'admin_users')]
#[ORM\UniqueConstraint(name: 'uk_admin_users_email', columns: ['email'])]
class AdminUser
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 180)]
    private string $email;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $displayName;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $passwordHash;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: AdminRole::class)]
    private AdminRole $role;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $totpSecretEncrypted = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $email, string $displayName, string $passwordHash, AdminRole $role)
    {
        $this->id = $id;
        $this->email = mb_strtolower($email);
        $this->displayName = $displayName;
        $this->passwordHash = $passwordHash;
        $this->role = $role;
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

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function role(): AdminRole
    {
        return $this->role;
    }

    public function totpSecretEncrypted(): ?string
    {
        return $this->totpSecretEncrypted;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function lastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function changeRole(AdminRole $role): void
    {
        $this->role = $role;
        $this->touch();
    }

    public function changePassword(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
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

    public function enrolTotp(string $secretEncrypted): void
    {
        $this->totpSecretEncrypted = $secretEncrypted;
        $this->touch();
    }

    public function resetTotp(): void
    {
        $this->totpSecretEncrypted = null;
        $this->touch();
    }

    public function recordLogin(\DateTimeImmutable $at): void
    {
        $this->lastLoginAt = $at;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
