<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use App\Admin\Entity\AdminUser;
use App\Crm\Repository\ActivityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One timeline entry (S13, D7), linked to its objects through ActivityCompany and ActivityDeal.
 * `title` and `body` are both nullable: a manual entry stores its text in `body` only, `title`
 * serves #29 and #30 (P7); one of them is set until the entry is anonymised
 * (chk_crm_activities_content). `actor` is null for entries without a staff author (#29).
 * Anonymising blanks title, body and payload for good (chk_crm_activities_anonymised, D16).
 */
#[ORM\Entity(repositoryClass: ActivityRepository::class)]
#[ORM\Table(name: 'crm_activities')]
#[ORM\Index(name: 'idx_crm_activities_type_code', columns: ['type_code'])]
#[ORM\Index(name: 'idx_crm_activities_actor_id', columns: ['actor_id'])]
#[ORM\Index(name: 'idx_crm_activities_occurred_at_id', columns: ['occurred_at', 'id'])]
class Activity
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: ActivityType::class)]
    #[ORM\JoinColumn(name: 'type_code', referencedColumnName: 'code', nullable: false, onDelete: 'RESTRICT')]
    private ActivityType $type;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true, onDelete: 'RESTRICT')]
    private ?AdminUser $actor;

    #[ORM\Column(type: Types::STRING, length: 200, nullable: true)]
    private ?string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body;

    /**
     * An empty payload is held as a stdClass, so it is stored as `{}` (chk_crm_activities_payload),
     * not as the `[]` an empty PHP array encodes to.
     *
     * @var array<string, mixed>|\stdClass
     */
    #[ORM\Column(type: Types::JSONB, options: ['default' => '{}'])]
    private array|\stdClass $payload;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $anonymisedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $id,
        ActivityType $type,
        \DateTimeImmutable $occurredAt,
        ?AdminUser $actor,
        ?string $title,
        ?string $body,
        array $payload = [],
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->occurredAt = $occurredAt;
        $this->actor = $actor;
        $this->title = $title;
        $this->body = $body;
        $this->payload = [] === $payload ? new \stdClass() : $payload;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function rewrite(?string $body, \DateTimeImmutable $occurredAt): void
    {
        $this->body = $body;
        $this->occurredAt = $occurredAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function anonymise(\DateTimeImmutable $at): void
    {
        $this->title = null;
        $this->body = null;
        $this->payload = new \stdClass();
        $this->anonymisedAt = $at;
        $this->updatedAt = $at;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): ActivityType
    {
        return $this->type;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function actor(): ?AdminUser
    {
        return $this->actor;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        /* @var array<string, mixed> */
        return (array) $this->payload;
    }

    public function anonymisedAt(): ?\DateTimeImmutable
    {
        return $this->anonymisedAt;
    }

    public function isAnonymised(): bool
    {
        return null !== $this->anonymisedAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
