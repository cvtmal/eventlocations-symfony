<?php

declare(strict_types=1);

namespace App\Inquiries\Repository;

use App\Inquiries\Entity\InquiryBrief;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InquiryBrief>
 */
final class InquiryBriefRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InquiryBrief::class);
    }

    public function add(InquiryBrief $brief): void
    {
        $this->getEntityManager()->persist($brief);
    }

    /**
     * Claims the brief until the transaction ends, so a repeated POST waits for the confirmation in progress and then
     * reads what it recorded (like `InquiryRepository::claim()`).
     *
     * @throws \LogicException when the row is missing: callers pass an id they just created
     */
    public function claim(string $id): InquiryBrief
    {
        return $this->find($id, LockMode::PESSIMISTIC_WRITE) ?? throw new \LogicException(\sprintf('Brief "%s" does not exist.', $id));
    }

    public function findLatestIdByFingerprint(string $fingerprint, \DateTimeImmutable $createdAfter): ?string
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT id FROM inquiry_briefs WHERE fingerprint = ? AND created_at > ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [$fingerprint, $createdAfter->format(\DATE_ATOM)],
        );

        return false === $id ? null : (string) $id;
    }

    public function countCreatedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne('SELECT count(*) FROM inquiry_briefs WHERE created_at < ?', [$cutoff->format(\DATE_ATOM)]);
    }

    /**
     * Deletes the briefs older than the cutoff; their children are deleted by the inquiry retention with the same
     * cutoff before, or by the cascade.
     */
    public function deleteCreatedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement('DELETE FROM inquiry_briefs WHERE created_at < ?', [$cutoff->format(\DATE_ATOM)]);
    }
}
