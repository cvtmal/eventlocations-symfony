<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Puts an activity on a deal's timeline (S13), the sibling of ActivityCompany and ActivityContact.
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_activity_deals')]
#[ORM\Index(name: 'idx_crm_activity_deals_activity_id', columns: ['activity_id'])]
#[ORM\Index(name: 'idx_crm_activity_deals_deal_id', columns: ['deal_id'])]
class ActivityDeal
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Activity::class)]
    #[ORM\JoinColumn(name: 'activity_id', nullable: false, onDelete: 'CASCADE')]
    private Activity $activity;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Deal::class)]
    #[ORM\JoinColumn(name: 'deal_id', nullable: false, onDelete: 'CASCADE')]
    private Deal $deal;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Activity $activity, Deal $deal)
    {
        $this->activity = $activity;
        $this->deal = $deal;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function activity(): Activity
    {
        return $this->activity;
    }

    public function deal(): Deal
    {
        return $this->deal;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
