<?php

declare(strict_types=1);

namespace App\Venues\Repository;

use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueDeletedContentKey;
use App\Venues\Entity\VenueTranslation;
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
     * The venue's id for its public number, without loading the entity (VenueEditing locks
     * it by id).
     */
    public function idByNumber(int $number): ?string
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne('SELECT id FROM venues WHERE number = :number', ['number' => $number]);

        return false === $id ? null : (string) $id;
    }

    /**
     * The venue's translations by locale, for the CMS handlers (#24) that write them.
     *
     * @return array<string, VenueTranslation>
     */
    public function translationsByLocale(Venue $venue): array
    {
        $rows = [];
        foreach ($this->getEntityManager()->getRepository(VenueTranslation::class)->findBy(['venue' => $venue]) as $row) {
            $rows[$row->locale()] = $row;
        }

        return $rows;
    }

    /**
     * Whether a venue with this content key was deleted in the CMS (#24): its folder must
     * not bring it back unless the import is told to overwrite CMS edits.
     */
    public function isContentKeyDeleted(string $key): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM venue_deleted_content_keys WHERE content_key = :key',
            ['key' => $key],
        );
    }

    /**
     * Removes the tombstone of a deleted venue's content key, when the import recreates it.
     */
    public function forgetDeletedContentKey(string $key): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->delete(VenueDeletedContentKey::class, 'k')
            ->where('k.contentKey = :key')
            ->setParameter('key', $key)
            ->getQuery()
            ->execute();
    }

    /**
     * The originals (photo original_path, document path, relative to MEDIA_ROOT) of this
     * venue that no other venue's row uses, so deleting the venue may delete these files
     * and no others. Demo venues share their originals.
     *
     * @return list<string> sorted
     */
    public function originalsUsedOnlyBy(Venue $venue): array
    {
        /** @var list<string> $paths */
        $paths = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT p.original_path FROM venue_photos p
                WHERE p.venue_id = :venue
                    AND NOT EXISTS (SELECT 1 FROM venue_photos o WHERE o.original_path = p.original_path AND o.venue_id <> :venue)
                UNION
                SELECT d.path FROM venue_documents d
                WHERE d.venue_id = :venue
                    AND NOT EXISTS (SELECT 1 FROM venue_documents o WHERE o.path = d.path AND o.venue_id <> :venue)
                ORDER BY 1
                SQL,
            ['venue' => $venue->id()],
        );

        return $paths;
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
