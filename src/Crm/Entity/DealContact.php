<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A contact on a deal (S13). Both sides cascade, so erasing a contact drops the link and keeps the deal, a business
 * record (D28).
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_deal_contacts')]
#[ORM\Index(name: 'idx_crm_deal_contacts_deal_id', columns: ['deal_id'])]
#[ORM\Index(name: 'idx_crm_deal_contacts_contact_id', columns: ['contact_id'])]
class DealContact
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Deal::class)]
    #[ORM\JoinColumn(name: 'deal_id', nullable: false, onDelete: 'CASCADE')]
    private Deal $deal;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'contact_id', nullable: false, onDelete: 'CASCADE')]
    private Contact $contact;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Deal $deal, Contact $contact)
    {
        $this->deal = $deal;
        $this->contact = $contact;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function deal(): Deal
    {
        return $this->deal;
    }

    public function contact(): Contact
    {
        return $this->contact;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
