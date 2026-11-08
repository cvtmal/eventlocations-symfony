<?php

declare(strict_types=1);

namespace App\Venues\Repository;

use App\Venues\Entity\Subscription;
use App\Venues\Entity\Venue;
use App\Venues\Exception\SubscriptionNotFound;
use App\Venues\ValueObject\SubscriptionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /**
     * @throws SubscriptionNotFound
     */
    public function get(string $id): Subscription
    {
        $subscription = Uuid::isValid($id) ? $this->find($id) : null;

        return $subscription ?? throw SubscriptionNotFound::forId($id);
    }

    /**
     * Whether a term of the venue shares a day with `$startsOn` to `$endsOn`.
     */
    public function overlaps(Venue $venue, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn): bool
    {
        return null !== $this->createQueryBuilder('s')
            ->select('s.id')
            ->where('s.venue = :venue AND s.startsOn <= :endsOn AND s.endsOn >= :startsOn')
            ->setParameter('venue', $venue)
            ->setParameter('startsOn', $startsOn->format('Y-m-d'))
            ->setParameter('endsOn', $endsOn->format('Y-m-d'))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The venue's active terms that ended before `$today`.
     *
     * @return list<Subscription>
     */
    public function endedBefore(Venue $venue, \DateTimeImmutable $today): array
    {
        /* @var list<Subscription> */
        return $this->createQueryBuilder('s')
            ->where('s.venue = :venue AND s.status = :active AND s.endsOn < :today')
            ->setParameter('venue', $venue)
            ->setParameter('active', SubscriptionStatus::Active)
            ->setParameter('today', $today->format('Y-m-d'))
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether the venue has a term starting after this one (a renewal).
     */
    public function laterThan(Subscription $subscription): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM subscriptions WHERE venue_id = ? AND starts_on > ?)',
            [$subscription->venue()->id(), $subscription->startsOn()->format('Y-m-d')],
        );
    }
}
