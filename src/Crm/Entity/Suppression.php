<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use App\Crm\Repository\SuppressionRepository;
use App\Crm\ValueObject\SuppressionReason;
use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One entry of the suppression list (G11): a Location or a lower-case address we never contact for outreach again,
 * neither by mail nor by letter. Permanent: nothing deletes a row, and the Location a row names cannot be deleted
 * (`fk_suppressions_company_id` RESTRICT). Rows are written by `SuppressionRepository` with `ON CONFLICT DO NOTHING`;
 * this mapping keeps `doctrine:schema:validate` in step.
 */
#[ORM\Entity(repositoryClass: SuppressionRepository::class)]
#[ORM\Table(name: 'suppressions')]
#[ORM\UniqueConstraint(name: 'uk_suppressions_company_id', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uk_suppressions_email', columns: ['email'])]
class Suppression
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    /** The unique key doubles as the company_id index. */
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Company $company;

    #[ORM\Column(type: Types::STRING, length: 254, nullable: true)]
    private ?string $email;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: SuppressionReason::class)]
    private SuppressionReason $reason;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    private function __construct(?Company $company, ?string $email, SuppressionReason $reason, \DateTimeImmutable $at)
    {
        $this->id = EntityId::generate();
        $this->company = $company;
        $this->email = $email;
        $this->reason = $reason;
        $this->createdAt = $at;
        $this->updatedAt = $at;
    }

    public function id(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function reason(): SuppressionReason
    {
        return $this->reason;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
