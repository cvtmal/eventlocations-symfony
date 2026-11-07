<?php

declare(strict_types=1);

namespace App\Venues\Repository;

use App\Venues\Entity\ModerationCase;
use App\Venues\Entity\ModerationCasePhoto;
use App\Venues\Exception\ModerationCaseNotFound;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * The complaints of the public form (G2), for the handlers that create and decide them.
 *
 * @extends ServiceEntityRepository<ModerationCase>
 */
class ModerationCaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModerationCase::class);
    }

    /**
     * @throws ModerationCaseNotFound for an unknown id, or one that is no UUID (Postgres would refuse it with a 500)
     */
    public function get(string $id): ModerationCase
    {
        $case = Uuid::isValid($id) ? $this->find($id) : null;

        return $case ?? throw ModerationCaseNotFound::forId($id);
    }

    /**
     * @return list<ModerationCasePhoto> the photo rows of the case, in the order they were named
     */
    public function photosOf(ModerationCase $case): array
    {
        return $this->getEntityManager()->getRepository(ModerationCasePhoto::class)->findBy(['case' => $case], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }
}
