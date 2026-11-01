<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use App\Crm\Repository\DealStageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A column of the pipeline (S13, D5): a code-keyed row seeded by migrations, so a new stage is an insert and no
 * code change. There is no PHP enum on purpose: queries and the island iterate the rows, labels are
 * `deal.stage.<code>` in the `crm` catalogue. `probability` is stored as S13 says and shown nowhere.
 * Read-only for the application.
 */
#[ORM\Entity(repositoryClass: DealStageRepository::class, readOnly: true)]
#[ORM\Table(name: 'crm_deal_stages')]
#[ORM\UniqueConstraint(name: 'uk_crm_deal_stages_position', columns: ['position'])]
#[ORM\UniqueConstraint(name: 'uk_crm_deal_stages_won', columns: ['is_won'], options: ['where' => 'is_won'])]
#[ORM\UniqueConstraint(name: 'uk_crm_deal_stages_lost', columns: ['is_lost'], options: ['where' => 'is_lost'])]
class DealStage
{
    /** Where every new deal starts (N5). */
    public const NEW = 'new';

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 30)]
    private string $code;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $probability;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isWon;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isLost;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    private function __construct(string $code, int $position, int $probability, bool $isWon, bool $isLost)
    {
        $this->code = $code;
        $this->position = $position;
        $this->probability = $probability;
        $this->isWon = $isWon;
        $this->isLost = $isLost;
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

    public function probability(): int
    {
        return $this->probability;
    }

    public function isWon(): bool
    {
        return $this->isWon;
    }

    public function isLost(): bool
    {
        return $this->isLost;
    }

    /** Won or lost: the deal has left the open pipeline. */
    public function isClosed(): bool
    {
        return $this->isWon || $this->isLost;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
