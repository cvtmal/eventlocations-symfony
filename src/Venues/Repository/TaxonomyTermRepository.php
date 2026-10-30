<?php

declare(strict_types=1);

namespace App\Venues\Repository;

use App\Venues\Entity\TaxonomyTerm;
use App\Venues\ValueObject\TermGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaxonomyTerm>
 */
class TaxonomyTermRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaxonomyTerm::class);
    }

    /**
     * The terms of one group in display order.
     *
     * @return list<TaxonomyTerm>
     */
    public function byGroup(TermGroup $group): array
    {
        return $this->findBy(['termGroup' => $group], ['position' => 'ASC']);
    }
}
