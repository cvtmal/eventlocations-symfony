<?php

declare(strict_types=1);

namespace App\Inquiries\Repository;

use App\Inquiries\Entity\Inquiry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Inquiry>
 */
class InquiryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Inquiry::class);
    }

    public function add(Inquiry $inquiry): void
    {
        $this->getEntityManager()->persist($inquiry);
    }

    /**
     * @throws \LogicException when the row is missing: callers pass an id they just created
     */
    public function get(string $id): Inquiry
    {
        return $this->find($id) ?? throw new \LogicException(\sprintf('Inquiry "%s" does not exist.', $id));
    }

    /**
     * Claims the row until the transaction ends, across the SMTP calls: a second caller waits
     * here and then reads what the first one recorded. With a pessimistic lock `find()` re-reads
     * an entity that is already managed, so the status is from after the wait. A process that dies
     * loses its connection and with it the claim, so nothing has to expire.
     *
     * @throws \LogicException when the row is missing: callers pass an id they just created
     */
    public function claim(string $id): Inquiry
    {
        return $this->find($id, LockMode::PESSIMISTIC_WRITE) ?? throw new \LogicException(\sprintf('Inquiry "%s" does not exist.', $id));
    }

    /**
     * Makes inquiries with this fingerprint wait for each other until the transaction ends, so
     * the caller must be in one. Transaction level, because a session-level lock would stay with
     * a persistent or pooled connection. A hash collision only makes two inquiries take turns.
     */
    public function lockFingerprint(string $fingerprint): void
    {
        $this->getEntityManager()->getConnection()->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$fingerprint]);
    }

    /**
     * Makes briefs to this venue wait for each other until the transaction ends (F13), so two at once cannot both take
     * the last place under the daily cap. Callers lock their venues in a fixed order, so two briefs cannot deadlock.
     */
    public function lockVenueForBriefs(string $venueId): void
    {
        $this->lockFingerprint('inquiry-brief-venue|'.$venueId);
    }

    /**
     * How many children of briefs the venue got since `$since` (the daily cap, `BriefRules`).
     */
    public function countBriefChildrenSince(string $venueId, \DateTimeImmutable $since): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT count(*) FROM inquiries WHERE venue_id = ? AND brief_id IS NOT NULL AND created_at >= ?',
            [$venueId, $since->format(\DATE_ATOM)],
        );
    }

    /**
     * Whether another child of the brief has had no delivery outcome yet (F13: the confirmation waits for it).
     */
    public function hasPendingSibling(string $briefId, string $inquiryId): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT 1 FROM inquiries WHERE brief_id = ? AND id <> ? AND venue_mail_status = 'pending' LIMIT 1",
            [$briefId, $inquiryId],
        );
    }

    /**
     * The children of a brief, in the order the guest chose the venues (ids are time-ordered UUIDs made in that order).
     *
     * @return list<Inquiry>
     */
    public function childrenOf(string $briefId): array
    {
        return $this->createQueryBuilder('i')
            ->where('IDENTITY(i.brief) = :brief')
            ->setParameter('brief', $briefId)
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestIdByFingerprint(string $fingerprint, \DateTimeImmutable $createdAfter): ?string
    {
        $id = $this->createQueryBuilder('i')
            ->select('i.id')
            ->where('i.fingerprint = :fingerprint')
            ->andWhere('i.createdAt > :createdAfter')
            ->setParameter('fingerprint', $fingerprint)
            ->setParameter('createdAfter', $createdAfter, Types::DATETIMETZ_IMMUTABLE)
            ->orderBy('i.createdAt', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);
        \assert(null === $id || \is_string($id));

        return $id;
    }

    /**
     * Notes that the staff alert reported these inquiries (G7), so the next hourly run leaves them out.
     *
     * @param list<string> $ids
     */
    public function recordStuckAlerted(array $ids, \DateTimeImmutable $at): void
    {
        if ([] === $ids) {
            return;
        }

        $this->getEntityManager()
            ->createQuery('UPDATE '.Inquiry::class.' i SET i.stuckAlertedAt = :at WHERE i.id IN (:ids)')
            ->setParameter('at', $at, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('ids', $ids)
            ->execute();
    }

    public function countCreatedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteCreatedBefore(\DateTimeImmutable $cutoff): int
    {
        $deleted = $this->getEntityManager()
            ->createQuery('DELETE FROM '.Inquiry::class.' i WHERE i.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->execute();
        \assert(\is_int($deleted));

        return $deleted;
    }
}
