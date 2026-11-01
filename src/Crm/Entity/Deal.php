<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use App\Admin\Entity\AdminUser;
use App\Crm\Repository\DealRepository;
use App\Crm\ValueObject\LostReason;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\Tier;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A sale to a company (S13, CRM-7): a stage in the pipeline, a venue, a tier, an amount in Rappen and a contract's
 * days. The company never changes, because activities, the venue and the stage sync hang on it: delete and
 * recreate instead. `company` is RESTRICT, so deleting a company never erases contract history; `venue` is SET NULL,
 * because Venues may not know the CRM. `renewedFrom` is unique, so a deal is renewed once.
 *
 * idx_crm_deals_search_key (GIN trigram on geo_search_key(name)) is an expression index and unmapped, like
 * idx_venues_search_key.
 */
#[ORM\Entity(repositoryClass: DealRepository::class)]
#[ORM\Table(name: 'crm_deals')]
#[ORM\Index(name: 'idx_crm_deals_company_id', columns: ['company_id'])]
#[ORM\Index(name: 'idx_crm_deals_venue_id', columns: ['venue_id'])]
#[ORM\Index(name: 'idx_crm_deals_stage_code', columns: ['stage_code'])]
#[ORM\Index(name: 'idx_crm_deals_owner_id', columns: ['owner_id'])]
#[ORM\Index(name: 'idx_crm_deals_contract_end_on', columns: ['contract_end_on'])]
#[ORM\UniqueConstraint(name: 'uk_crm_deals_renewed_from_id', columns: ['renewed_from_id'])]
class Deal
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 150)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: true, onDelete: 'SET NULL')]
    private ?Venue $venue;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true, enumType: Tier::class)]
    private ?Tier $tier;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $amountRappen;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $contractStartOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $contractEndOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expectedCloseOn;

    #[ORM\ManyToOne(targetEntity: DealStage::class)]
    #[ORM\JoinColumn(name: 'stage_code', referencedColumnName: 'code', nullable: false, onDelete: 'RESTRICT')]
    private DealStage $stage;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: true, onDelete: 'RESTRICT')]
    private ?AdminUser $owner;

    #[ORM\Column(type: Types::STRING, length: 30, nullable: true, enumType: LostReason::class)]
    private ?LostReason $lostReason = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'renewed_from_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $renewedFrom;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $name,
        Company $company,
        DealStage $stage,
        ?Venue $venue = null,
        ?Tier $tier = null,
        ?int $amountRappen = null,
        ?\DateTimeImmutable $contractStartOn = null,
        ?\DateTimeImmutable $contractEndOn = null,
        ?\DateTimeImmutable $expectedCloseOn = null,
        ?AdminUser $owner = null,
        ?self $renewedFrom = null,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->company = $company;
        $this->stage = $stage;
        $this->venue = $venue;
        $this->tier = $tier;
        $this->amountRappen = $amountRappen;
        $this->contractStartOn = $contractStartOn;
        $this->contractEndOn = $contractEndOn;
        $this->expectedCloseOn = $expectedCloseOn;
        $this->owner = $owner;
        $this->renewedFrom = $renewedFrom;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function changeVenue(?Venue $venue): void
    {
        $this->venue = $venue;
        $this->touch();
    }

    public function changeTier(?Tier $tier): void
    {
        $this->tier = $tier;
        $this->touch();
    }

    public function changeAmount(?int $rappen): void
    {
        $this->amountRappen = $rappen;
        $this->touch();
    }

    public function changeContractStart(?\DateTimeImmutable $on): void
    {
        $this->contractStartOn = $on;
        $this->touch();
    }

    public function changeContractEnd(?\DateTimeImmutable $on): void
    {
        $this->contractEndOn = $on;
        $this->touch();
    }

    public function changeExpectedClose(?\DateTimeImmutable $on): void
    {
        $this->expectedCloseOn = $on;
        $this->touch();
    }

    public function assignOwner(?AdminUser $owner): void
    {
        $this->owner = $owner;
        $this->touch();
    }

    /**
     * A closed stage stamps `closedAt`, a lost one also keeps the reason; moving back to an open stage clears both,
     * and leaving lost for won drops the reason. The handler requires the reason for a lost stage.
     */
    public function moveTo(DealStage $stage, ?LostReason $reason, \DateTimeImmutable $at): void
    {
        $this->stage = $stage;
        $this->lostReason = $stage->isLost() ? $reason : null;
        $this->closedAt = $stage->isClosed() ? $at : null;
        $this->updatedAt = $at;
    }

    /**
     * A lost deal's reason, corrected without leaving the stage: `closedAt` stays the day it was lost.
     */
    public function changeLostReason(LostReason $reason): void
    {
        $this->lostReason = $reason;
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

    public function company(): Company
    {
        return $this->company;
    }

    public function venue(): ?Venue
    {
        return $this->venue;
    }

    public function tier(): ?Tier
    {
        return $this->tier;
    }

    public function amountRappen(): ?int
    {
        return $this->amountRappen;
    }

    public function contractStartOn(): ?\DateTimeImmutable
    {
        return $this->contractStartOn;
    }

    public function contractEndOn(): ?\DateTimeImmutable
    {
        return $this->contractEndOn;
    }

    public function expectedCloseOn(): ?\DateTimeImmutable
    {
        return $this->expectedCloseOn;
    }

    public function stage(): DealStage
    {
        return $this->stage;
    }

    public function owner(): ?AdminUser
    {
        return $this->owner;
    }

    public function lostReason(): ?LostReason
    {
        return $this->lostReason;
    }

    public function closedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function renewedFrom(): ?self
    {
        return $this->renewedFrom;
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
