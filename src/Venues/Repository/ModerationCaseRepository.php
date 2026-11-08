<?php

declare(strict_types=1);

namespace App\Venues\Repository;

use App\Venues\Entity\ModerationCase;
use App\Venues\Entity\ModerationCasePhoto;
use App\Venues\Entity\Venue;
use App\Venues\Exception\ModerationCaseNotFound;
use App\Venues\ValueObject\ModerationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
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
     * The case, locked (`FOR UPDATE`) until the command's transaction ends: of two decisions at once (a double
     * click, a second tab), the second waits for the first and then finds the case decided.
     *
     * @throws ModerationCaseNotFound for an unknown id, or one that is no UUID (Postgres would refuse it with a 500)
     */
    public function get(string $id): ModerationCase
    {
        $case = Uuid::isValid($id) ? $this->find($id, LockMode::PESSIMISTIC_WRITE) : null;

        return $case ?? throw ModerationCaseNotFound::forId($id);
    }

    /**
     * Whether another open case of the venue took the entry offline: then a `keep` of this one leaves it offline.
     */
    public function otherOpenCaseTookEntryOffline(ModerationCase $case, Venue $venue): bool
    {
        return 0 < (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.venue = :venue')
            ->andWhere('c.status = :open')
            ->andWhere('c.tookEntryOffline = true')
            ->andWhere('c.id <> :id')
            ->setParameter('venue', $venue)
            ->setParameter('open', ModerationStatus::Open)
            ->setParameter('id', $case->id())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * D36: the decided cases decided on or before `$cutoff` (three years ago less a day) whose complainant is still
     * stored. A handful per year on this site, so they are loaded, not bulk-updated.
     *
     * @return list<ModerationCase>
     */
    public function complainantsDueForErasure(\DateTimeImmutable $cutoff): array
    {
        /** @var list<ModerationCase> $cases */
        $cases = $this->createQueryBuilder('c')
            ->where('c.status = :decided')
            ->andWhere('c.decidedAt <= :cutoff')
            ->andWhere('c.complainantErasedAt IS NULL')
            ->orderBy('c.decidedAt')
            ->addOrderBy('c.id')
            ->setParameter('decided', ModerationStatus::Decided)
            ->setParameter('cutoff', $cutoff, Types::DATETIMETZ_IMMUTABLE)
            ->getQuery()
            ->getResult();

        return $cases;
    }

    /**
     * @return list<ModerationCasePhoto> the photo rows of the case, in the order they were named
     */
    public function photosOf(ModerationCase $case): array
    {
        return $this->getEntityManager()->getRepository(ModerationCasePhoto::class)->findBy(['case' => $case], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }
}
