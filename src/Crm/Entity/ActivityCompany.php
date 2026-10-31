<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Puts an activity on a company's timeline (S13). One link table per object, so #28 adds
 * crm_activity_deals without touching this one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_activity_companies')]
#[ORM\Index(name: 'idx_crm_activity_companies_activity_id', columns: ['activity_id'])]
#[ORM\Index(name: 'idx_crm_activity_companies_company_id', columns: ['company_id'])]
class ActivityCompany
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Activity::class)]
    #[ORM\JoinColumn(name: 'activity_id', nullable: false, onDelete: 'CASCADE')]
    private Activity $activity;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Activity $activity, Company $company)
    {
        $this->activity = $activity;
        $this->company = $company;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function activity(): Activity
    {
        return $this->activity;
    }

    public function company(): Company
    {
        return $this->company;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
