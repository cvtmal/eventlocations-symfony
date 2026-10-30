<?php

declare(strict_types=1);

namespace App\Venues\Repository;

use App\Venues\Entity\Venue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Venue>
 */
class VenueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Venue::class);
    }

    /**
     * Every venue number ascending; with $only, `[$only]` if that venue exists, else `[]`.
     *
     * @return list<int>
     */
    public function numbers(?int $only = null): array
    {
        $query = $this->createQueryBuilder('v')->select('v.number')->orderBy('v.number');
        if (null !== $only) {
            $query->where('v.number = :number')->setParameter('number', $only);
        }

        return array_values(array_map('intval', $query->getQuery()->getSingleColumnResult()));
    }
}
