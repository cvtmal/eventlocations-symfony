<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use App\Crm\Repository\ContactRepository;
use App\Crm\ValueObject\ContactEmail;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A person on one Location's contact card (S13, CRM-2; #55): the row belongs to its company and goes
 * with it (CASCADE). The same person on two Locations is two rows. The e-mail goes through
 * ContactEmail, so it is stored lower-case and the unique key on (company_id, email) means "unique
 * per Location when present, case-insensitively" (D6). Only the last name is required: a venue's
 * contact person may be a single word (D19).
 *
 * idx_crm_contacts_search_key (GIN trigram on geo_search_key(coalesce(first_name, '') || ' ' ||
 * last_name)) is an expression index that exists in the database only.
 */
#[ORM\Entity(repositoryClass: ContactRepository::class)]
#[ORM\Table(name: 'crm_contacts')]
#[ORM\Index(name: 'idx_crm_contacts_company_id', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uk_crm_contacts_company_email', columns: ['company_id', 'email'])]
class Contact
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 150, nullable: true)]
    private ?string $firstName;

    #[ORM\Column(type: Types::STRING, length: 150)]
    private string $lastName;

    #[ORM\Column(type: Types::STRING, length: 254, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: Types::STRING, length: 150, nullable: true)]
    private ?string $position = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        Company $company,
        ?string $firstName,
        string $lastName,
        ?string $email = null,
    ) {
        $this->id = $id;
        $this->company = $company;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = ContactEmail::normalise($email);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function changeFirstName(?string $firstName): void
    {
        $this->firstName = $firstName;
        $this->touch();
    }

    public function changeLastName(string $lastName): void
    {
        $this->lastName = $lastName;
        $this->touch();
    }

    public function changeEmail(?string $email): void
    {
        $this->email = ContactEmail::normalise($email);
        $this->touch();
    }

    public function changePhone(?string $phone): void
    {
        $this->phone = $phone;
        $this->touch();
    }

    public function changePosition(?string $position): void
    {
        $this->position = $position;
        $this->touch();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function firstName(): ?string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function fullName(): string
    {
        return null === $this->firstName ? $this->lastName : $this->firstName.' '.$this->lastName;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function position(): ?string
    {
        return $this->position;
    }

    public function company(): Company
    {
        return $this->company;
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
