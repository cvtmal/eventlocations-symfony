<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A taxonomy term assigned to a venue. The table stores the term's natural key
 * (term_group, term_code), so the search filters by code without a join while the
 * composite FK keeps every code valid.
 *
 * `is_main` marks the venue's main term of a group (its main building type, main setting):
 * at most one per venue and group (uk_venue_terms_venue_id_term_group_main, a partial
 * unique index WHERE is_main).
 *
 * Mapping: Doctrine cannot use an association to an entity with a composite key
 * (TaxonomyTerm) as part of a primary key. So the identifier is `venue` plus the scalar
 * columns termGroup/termCode, which are read-only (insertable/updatable false); the
 * `term` association on the same two columns writes them and carries
 * fk_venue_terms_term, so schema:validate keeps the FK. The constructor sets both from
 * the same TaxonomyTerm.
 */
#[ORM\Entity]
#[ORM\Table(name: 'venue_terms')]
#[ORM\Index(name: 'idx_venue_terms_venue_id', columns: ['venue_id'])]
#[ORM\Index(name: 'idx_venue_terms_term_group_term_code', columns: ['term_group', 'term_code'])]
#[ORM\UniqueConstraint(name: 'uk_venue_terms_venue_id_term_group_main', columns: ['venue_id', 'term_group'], options: ['where' => 'is_main'])]
class VenueTerm
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\Id]
    #[ORM\Column(name: 'term_group', type: Types::STRING, length: 20, insertable: false, updatable: false, enumType: TermGroup::class)]
    private TermGroup $termGroup;

    #[ORM\Id]
    #[ORM\Column(name: 'term_code', type: Types::STRING, length: 40, insertable: false, updatable: false)]
    private string $termCode;

    #[ORM\ManyToOne(targetEntity: TaxonomyTerm::class)]
    #[ORM\JoinColumn(name: 'term_group', referencedColumnName: 'term_group', nullable: false, onDelete: 'RESTRICT')]
    #[ORM\JoinColumn(name: 'term_code', referencedColumnName: 'code', nullable: false, onDelete: 'RESTRICT')]
    private TaxonomyTerm $term;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isMain;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Venue $venue, TaxonomyTerm $term, bool $isMain = false)
    {
        $this->venue = $venue;
        $this->term = $term;
        $this->termGroup = $term->termGroup();
        $this->termCode = $term->code();
        $this->isMain = $isMain;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function venue(): Venue
    {
        return $this->venue;
    }

    public function term(): TaxonomyTerm
    {
        return $this->term;
    }

    public function termGroup(): TermGroup
    {
        return $this->termGroup;
    }

    public function termCode(): string
    {
        return $this->termCode;
    }

    public function isMain(): bool
    {
        return $this->isMain;
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
