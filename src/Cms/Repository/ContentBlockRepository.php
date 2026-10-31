<?php

declare(strict_types=1);

namespace App\Cms\Repository;

use App\Cms\Entity\ContentBlock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentBlock>
 */
class ContentBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentBlock::class);
    }

    /**
     * Every locale's row of the given keys, by key and locale.
     *
     * @param list<string> $keys
     *
     * @return list<ContentBlock>
     */
    public function byKeys(array $keys): array
    {
        if ([] === $keys) {
            return [];
        }

        /** @var list<ContentBlock> $blocks */
        $blocks = $this->createQueryBuilder('b')
            ->where('b.key IN (:keys)')
            ->setParameter('keys', $keys)
            ->orderBy('b.key')
            ->addOrderBy('b.locale')
            ->getQuery()
            ->getResult();

        return $blocks;
    }

    public function add(ContentBlock $block): void
    {
        $this->getEntityManager()->persist($block);
    }

    public function remove(ContentBlock $block): void
    {
        $this->getEntityManager()->remove($block);
    }
}
