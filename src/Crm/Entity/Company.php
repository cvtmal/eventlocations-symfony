<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use App\Admin\Entity\AdminUser;
use App\Crm\Repository\CompanyRepository;
use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\CompanyStage;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A venue operator we sell to (S13, CRM-1). The id comes in through the constructor so the caller
 * knows it before dispatching (the AdminUser precedent); it still comes only from EntityId::generate().
 * Its venues hang off it through CompanyVenue. `stage` is set by hand until #28 derives it.
 * Values arrive canonical (trimmed, empty = null) from the handlers.
 *
 * idx_crm_companies_search_key (GIN trigram on geo_search_key(name)) is an expression index
 * that exists in the database only, as on Venue.
 */
#[ORM\Entity(repositoryClass: CompanyRepository::class)]
#[ORM\Table(name: 'crm_companies')]
#[ORM\Index(name: 'idx_crm_companies_owner_id', columns: ['owner_id'])]
class Company
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 150)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 200, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: Types::STRING, length: 254, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::STRING, length: 300, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: CompanyStage::class, options: ['default' => 'prospect'])]
    private CompanyStage $stage = CompanyStage::Prospect;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: true, onDelete: 'RESTRICT')]
    private ?AdminUser $owner = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: CompanyOrigin::class)]
    private CompanyOrigin $origin;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $name, CompanyOrigin $origin, ?AdminUser $owner = null)
    {
        $this->id = $id;
        $this->name = $name;
        $this->origin = $origin;
        $this->owner = $owner;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function changeLegalName(?string $legalName): void
    {
        $this->legalName = $legalName;
        $this->touch();
    }

    public function changeWebsite(?string $website): void
    {
        $this->website = $website;
        $this->touch();
    }

    public function changePhone(?string $phone): void
    {
        $this->phone = $phone;
        $this->touch();
    }

    public function changeEmail(?string $email): void
    {
        $this->email = null === $email ? null : mb_strtolower($email);
        $this->touch();
    }

    public function changeAddress(?string $address): void
    {
        $this->address = $address;
        $this->touch();
    }

    public function changeStage(CompanyStage $stage): void
    {
        $this->stage = $stage;
        $this->touch();
    }

    public function assignOwner(?AdminUser $owner): void
    {
        $this->owner = $owner;
        $this->touch();
    }

    public function changeNotes(?string $notes): void
    {
        $this->notes = $notes;
        $this->touch();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function legalName(): ?string
    {
        return $this->legalName;
    }

    public function website(): ?string
    {
        return $this->website;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function address(): ?string
    {
        return $this->address;
    }

    public function stage(): CompanyStage
    {
        return $this->stage;
    }

    public function owner(): ?AdminUser
    {
        return $this->owner;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function origin(): CompanyOrigin
    {
        return $this->origin;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
