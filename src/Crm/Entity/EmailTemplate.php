<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use App\Crm\Repository\EmailTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A mail template of the settings screen (CRM-10): plain text with single-brace merge fields, never Twig. A sent mail
 * keeps its own copy of the text, so nothing references a template. Names are unique, case-sensitive
 * (uk_crm_email_templates_name), and nothing here is blank (chk_crm_email_templates_content).
 */
#[ORM\Entity(repositoryClass: EmailTemplateRepository::class)]
#[ORM\Table(name: 'crm_email_templates')]
#[ORM\UniqueConstraint(name: 'uk_crm_email_templates_name', columns: ['name'])]
class EmailTemplate
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $subject;

    #[ORM\Column(type: Types::TEXT)]
    private string $bodyMarkdown;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $name, string $subject, string $bodyMarkdown)
    {
        $this->id = $id;
        $this->name = $name;
        $this->subject = $subject;
        $this->bodyMarkdown = $bodyMarkdown;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function rewrite(string $name, string $subject, string $bodyMarkdown): void
    {
        $this->name = $name;
        $this->subject = $subject;
        $this->bodyMarkdown = $bodyMarkdown;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function bodyMarkdown(): string
    {
        return $this->bodyMarkdown;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
