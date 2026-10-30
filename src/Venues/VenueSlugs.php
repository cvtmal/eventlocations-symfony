<?php

declare(strict_types=1);

namespace App\Venues;

use App\Geo\Entity\Town;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueSlugHistory;
use App\Venues\Entity\VenueTranslation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A venue's slug per locale and its history (#9, D7): the slug changes only with the name;
 * the old slug goes to venue_slug_history (the 301 of #8), and taking back one of the
 * venue's own history slugs deletes that history row.
 *
 * Callers: the content import (ImportVenueContentHandler) and the CMS rename (#24
 * RenameVenue). The caller creates or revises the VenueTranslation with the returned slug
 * and flushes in its own transaction.
 */
final class VenueSlugs
{
    public function __construct(
        private readonly SlugAllocator $allocator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param VenueTranslation|null $current     the venue's row in that locale, null when it has none yet
     * @param bool                  $nameChanged whether $name differs from the venue's previous name
     *
     * @return string the slug the locale's translation must carry
     */
    public function assign(Venue $venue, string $locale, string $name, ?Town $town, ?VenueTranslation $current, bool $nameChanged): string
    {
        if (null !== $current && !$nameChanged) {
            return $current->slug();
        }

        $slug = $this->allocator->allocate($locale, $name, $town?->slug(), $venue->id());
        if (null === $current || $current->slug() !== $slug) {
            $this->em->createQuery(\sprintf('DELETE FROM %s h WHERE h.venue = :venue AND h.locale = :locale AND h.slug = :slug', VenueSlugHistory::class))
                ->setParameters(['venue' => $venue, 'locale' => $locale, 'slug' => $slug])
                ->execute();
        }
        if (null !== $current && $current->slug() !== $slug) {
            $this->em->persist(new VenueSlugHistory($venue, $locale, $current->slug()));
        }

        return $slug;
    }
}
