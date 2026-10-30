<?php

declare(strict_types=1);

namespace App\Venues\Repository;

use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
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

    public function findOneByContentKey(string $key): ?Venue
    {
        return $this->findOneBy(['contentKey' => $key]);
    }

    /**
     * Whether a venue other than $exceptVenueId has the slug in this locale, as its current
     * slug or in its slug history (history slugs stay reserved for their 301s).
     */
    public function isSlugTaken(string $locale, string $slug, ?string $exceptVenueId): bool
    {
        $except = null === $exceptVenueId ? '' : ' AND venue_id <> :except';
        $params = ['locale' => $locale, 'slug' => $slug];
        if (null !== $exceptVenueId) {
            $params['except'] = $exceptVenueId;
        }

        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            <<<SQL
                SELECT 1 FROM venue_translations WHERE locale = :locale AND slug = :slug{$except}
                UNION ALL
                SELECT 1 FROM venue_slug_history WHERE locale = :locale AND slug = :slug{$except}
                LIMIT 1
                SQL,
            $params,
        );
    }

    /**
     * Content venues (content_key set) whose key is not in $keys and that a prune would
     * still change: published, or with a content hash. Venues without a key (demo venues)
     * are never returned.
     *
     * @param list<string> $keys
     *
     * @return list<Venue> ordered by content key
     */
    public function contentVenuesMissingFrom(array $keys): array
    {
        $query = $this->createQueryBuilder('v')
            ->where('v.contentKey IS NOT NULL')
            ->andWhere('v.status = :published OR v.contentHash IS NOT NULL')
            ->setParameter('published', VenueStatus::Published)
            ->orderBy('v.contentKey');
        if ([] !== $keys) {
            $query->andWhere('v.contentKey NOT IN (:keys)')->setParameter('keys', $keys);
        }

        /** @var list<Venue> $venues */
        $venues = $query->getQuery()->getResult();

        return $venues;
    }
}
