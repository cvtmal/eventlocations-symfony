<?php

declare(strict_types=1);

namespace App\Crm\Repository;

use App\Crm\Entity\DealStage;
use App\Crm\Exception\UnknownDealStage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DealStage>
 */
class DealStageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DealStage::class);
    }

    /**
     * @throws UnknownDealStage
     */
    public function get(string $code): DealStage
    {
        return $this->find($code) ?? throw UnknownDealStage::forCode($code);
    }
}
