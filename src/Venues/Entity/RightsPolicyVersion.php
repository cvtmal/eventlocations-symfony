<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The text a rights-policy version stands for (G1), written the first time a grant uses it. The
 * version is the natural key (content-addressed, see RightsPolicyText), so a grant's
 * `policy_version` can always be read back to the words the grantor saw. `legacy` is the
 * migration's own row.
 */
#[ORM\Entity]
#[ORM\Table(name: 'rights_policy_versions')]
class RightsPolicyVersion
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $version;

    #[ORM\Column(type: Types::TEXT)]
    private string $bodyMarkdown;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $version, string $bodyMarkdown, \DateTimeImmutable $at)
    {
        $this->version = $version;
        $this->bodyMarkdown = $bodyMarkdown;
        $this->createdAt = $at;
        $this->updatedAt = $at;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function bodyMarkdown(): string
    {
        return $this->bodyMarkdown;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
