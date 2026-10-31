<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use App\Admin\Entity\AdminUser;
use App\Crm\Repository\TaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A to-do of a staff member (S13, CRM-5): a due date, an assignee, done or not, and at most one
 * object (chk_crm_tasks_one_object; none when created from the task list). #28 adds a deal link
 * and replaces that CHECK. A task goes with its company or contact (CASCADE).
 */
#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'crm_tasks')]
#[ORM\Index(name: 'idx_crm_tasks_assignee_id', columns: ['assignee_id'])]
#[ORM\Index(name: 'idx_crm_tasks_created_by_id', columns: ['created_by_id'])]
#[ORM\Index(name: 'idx_crm_tasks_company_id', columns: ['company_id'])]
#[ORM\Index(name: 'idx_crm_tasks_contact_id', columns: ['contact_id'])]
#[ORM\Index(name: 'idx_crm_tasks_due_on', columns: ['due_on'])]
class Task
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $title;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dueOn;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'assignee_id', nullable: false, onDelete: 'RESTRICT')]
    private AdminUser $assignee;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'RESTRICT')]
    private ?AdminUser $createdBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $doneAt = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: true, onDelete: 'CASCADE')]
    private ?Company $company;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'contact_id', nullable: true, onDelete: 'CASCADE')]
    private ?Contact $contact;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * @throws \LogicException when both a company and a contact are given (the handler decides first)
     */
    public function __construct(
        string $id,
        string $title,
        \DateTimeImmutable $dueOn,
        AdminUser $assignee,
        ?AdminUser $createdBy,
        ?Company $company = null,
        ?Contact $contact = null,
    ) {
        if (null !== $company && null !== $contact) {
            throw new \LogicException('A task is linked to one object at most.');
        }

        $this->id = $id;
        $this->title = $title;
        $this->dueOn = $dueOn;
        $this->assignee = $assignee;
        $this->createdBy = $createdBy;
        $this->company = $company;
        $this->contact = $contact;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function complete(\DateTimeImmutable $at): void
    {
        $this->doneAt = $at;
        $this->updatedAt = $at;
    }

    public function reopen(): void
    {
        $this->doneAt = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function dueOn(): \DateTimeImmutable
    {
        return $this->dueOn;
    }

    public function assignee(): AdminUser
    {
        return $this->assignee;
    }

    public function createdBy(): ?AdminUser
    {
        return $this->createdBy;
    }

    public function doneAt(): ?\DateTimeImmutable
    {
        return $this->doneAt;
    }

    public function isDone(): bool
    {
        return null !== $this->doneAt;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function contact(): ?Contact
    {
        return $this->contact;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
