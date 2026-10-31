<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Puts an activity on a contact's timeline (S13). One link table per object, so #28 adds
 * crm_activity_deals without touching this one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_activity_contacts')]
#[ORM\Index(name: 'idx_crm_activity_contacts_activity_id', columns: ['activity_id'])]
#[ORM\Index(name: 'idx_crm_activity_contacts_contact_id', columns: ['contact_id'])]
class ActivityContact
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Activity::class)]
    #[ORM\JoinColumn(name: 'activity_id', nullable: false, onDelete: 'CASCADE')]
    private Activity $activity;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'contact_id', nullable: false, onDelete: 'CASCADE')]
    private Contact $contact;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Activity $activity, Contact $contact)
    {
        $this->activity = $activity;
        $this->contact = $contact;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function activity(): Activity
    {
        return $this->activity;
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
