<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A timeline entry type (S13, D7): a code-keyed row seeded by migrations (`note`, `email_logged`,
 * `call`, `meeting` in #27, `deal_stage_changed` in #28). A later cycle adds a type by inserting a row, never by a CHECK change.
 * Labels are `activity_type.<code>` in the `crm` catalogue. Read-only for the application.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'crm_activity_types')]
#[ORM\UniqueConstraint(name: 'uk_crm_activity_types_position', columns: ['position'])]
class ActivityType
{
    public const NOTE = 'note';
    public const EMAIL_LOGGED = 'email_logged';
    public const CALL = 'call';
    public const MEETING = 'meeting';
    public const DEAL_STAGE_CHANGED = 'deal_stage_changed';

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 30)]
    private string $code;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    private function __construct(string $code, int $position)
    {
        $this->code = $code;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
