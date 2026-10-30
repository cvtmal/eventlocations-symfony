<?php

declare(strict_types=1);

namespace App\Admin\Entity;

use App\Admin\ValueObject\AuditActorType;
use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One audited command (S12, ADM-3), written by AuditLogMiddleware and read by AuditLogQuery. The log
 * is append-only, so the table has no updated_at and the entity no mutators.
 */
#[ORM\Entity]
#[ORM\Table(name: 'admin_audit_log')]
#[ORM\Index(name: 'idx_admin_audit_log_actor_id', columns: ['actor_id'])]
#[ORM\Index(name: 'idx_admin_audit_log_subject', columns: ['subject_type', 'subject_id', 'created_at'])]
#[ORM\Index(name: 'idx_admin_audit_log_created_at', columns: ['created_at'])]
class AuditLogEntry
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: AuditActorType::class)]
    private AuditActorType $actorType;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true, onDelete: 'RESTRICT')]
    private ?AdminUser $actor;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $command;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $subjectType;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $subjectId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSONB)]
    private array $changes;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $changes
     */
    public function __construct(AuditActorType $actorType, ?AdminUser $actor, string $command, string $subjectType, string $subjectId, array $changes, \DateTimeImmutable $createdAt)
    {
        $this->id = EntityId::generate();
        $this->actorType = $actorType;
        $this->actor = $actor;
        $this->command = $command;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->changes = $changes;
        $this->createdAt = $createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function actorType(): AuditActorType
    {
        return $this->actorType;
    }

    public function actor(): ?AdminUser
    {
        return $this->actor;
    }

    public function command(): string
    {
        return $this->command;
    }

    public function subjectType(): string
    {
        return $this->subjectType;
    }

    public function subjectId(): string
    {
        return $this->subjectId;
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
