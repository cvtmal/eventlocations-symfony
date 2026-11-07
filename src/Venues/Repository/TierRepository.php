<?php

declare(strict_types=1);

namespace App\Venues\Repository;

use App\Venues\Entity\Tier;
use App\Venues\Exception\TierNotFound;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tier>
 */
class TierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tier::class);
    }

    /**
     * @throws TierNotFound
     */
    public function byCode(string $code): Tier
    {
        return $this->findOneBy(['code' => $code]) ?? throw TierNotFound::forCode($code);
    }

    /**
     * The tier of a venue that has no better one, and of an entry whose paid term has lapsed (G9).
     *
     * @throws TierNotFound
     */
    public function free(): Tier
    {
        return $this->byCode(KnownTiers::FREE);
    }
}
