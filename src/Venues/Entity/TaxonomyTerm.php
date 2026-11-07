<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Venues\Repository\TaxonomyTermRepository;
use App\Venues\ValueObject\Term;
use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One term of a taxonomy group (Lage, Gebäudeart, …). Code-keyed vocabulary row (S4): the
 * primary key is the natural key (term_group, code), a documented exception to EntityId
 * like Geo\Entity\Canton. The 118 rows are seeded by the migrations; the label is
 * `term.<group>.<code>` in the `venues` catalogue (see term()).
 *
 * `position` orders the terms of a group; `primary_position` orders the building chips
 * shown before "Alle Gebäudearten" (FI-4) and is NULL for every other term.
 */
#[ORM\Entity(repositoryClass: TaxonomyTermRepository::class)]
#[ORM\Table(name: 'taxonomy_terms')]
#[ORM\UniqueConstraint(name: 'uk_taxonomy_terms_term_group_position', columns: ['term_group', 'position'])]
#[ORM\UniqueConstraint(name: 'uk_taxonomy_terms_term_group_primary_position', columns: ['term_group', 'primary_position'])]
class TaxonomyTerm
{
    #[ORM\Id]
    #[ORM\Column(name: 'term_group', type: Types::STRING, length: 20, enumType: TermGroup::class)]
    private TermGroup $termGroup;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $code;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $primaryPosition;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(TermGroup $termGroup, string $code, int $position, ?int $primaryPosition = null)
    {
        $this->termGroup = $termGroup;
        $this->code = $code;
        $this->position = $position;
        $this->primaryPosition = $primaryPosition;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function termGroup(): TermGroup
    {
        return $this->termGroup;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function term(): Term
    {
        return new Term($this->termGroup, $this->code);
    }

    public function position(): int
    {
        return $this->position;
    }

    public function primaryPosition(): ?int
    {
        return $this->primaryPosition;
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
