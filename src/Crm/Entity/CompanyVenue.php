<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use App\Crm\Repository\CompanyVenueRepository;
use App\Shared\Uid\EntityId;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A venue of a company (S13). A venue belongs to at most one company (uk_crm_company_venues_venue_id).
 * Deleting the venue drops the link by the database cascade and keeps the company (D3), so Venues
 * learns nothing of the CRM. The unique key doubles as the venue_id index.
 */
#[ORM\Entity(repositoryClass: CompanyVenueRepository::class)]
#[ORM\Table(name: 'crm_company_venues')]
#[ORM\Index(name: 'idx_crm_company_venues_company_id', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uk_crm_company_venues_venue_id', columns: ['venue_id'])]
class CompanyVenue
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Company $company, Venue $venue)
    {
        $this->id = EntityId::generate();
        $this->company = $company;
        $this->venue = $venue;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function company(): Company
    {
        return $this->company;
    }

    public function venue(): Venue
    {
        return $this->venue;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
