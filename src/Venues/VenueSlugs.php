<?php

declare(strict_types=1);

namespace App\Venues;

use App\Geo\Entity\Town;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueSlugHistory;
use App\Venues\Entity\VenueTranslation;
use App\Venues\Exception\VenueRuleViolation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A venue's slug per locale and its history (#9, D7): the slug changes only with the name;
 * the old slug goes to venue_slug_history (the 301 of #8), and taking back one of the
 * venue's own history slugs deletes that history row.
 *
 * Callers: the content import (ImportVenueContentHandler) and the CMS basics card (#24
 * ChangeVenueBasics), which uses assign() for a rename and change() for a slug typed by
 * hand. With assign() the caller creates or revises the VenueTranslation with the returned
 * slug; change() sets it itself. Either way the caller flushes in its own transaction.
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
        $this->moveTo($venue, $locale, $current?->slug(), $slug);

        return $slug;
    }

    /**
     * Gives the translation a slug typed by hand (#24): a no-op when it is the current one.
     * Another venue's current or history slug is refused; one of the venue's own history slugs
     * is taken back. The shape (kebab case, length) is the form's business.
     *
     * @throws VenueRuleViolation slugTaken()
     */
    public function change(Venue $venue, VenueTranslation $current, string $slug): void
    {
        if ($current->slug() === $slug) {
            return;
        }
        if ($this->allocator->isTaken($current->locale(), $slug, $venue->id())) {
            throw VenueRuleViolation::slugTaken();
        }

        $this->moveTo($venue, $current->locale(), $current->slug(), $slug);
        $current->changeSlug($slug);
    }

    /**
     * The history side of a slug change: the venue's own history row of the new slug goes
     * (it is current again), the old slug becomes history.
     */
    private function moveTo(Venue $venue, string $locale, ?string $oldSlug, string $newSlug): void
    {
        if ($oldSlug === $newSlug) {
            return;
        }
        $this->em->createQuery(\sprintf('DELETE FROM %s h WHERE h.venue = :venue AND h.locale = :locale AND h.slug = :slug', VenueSlugHistory::class))
            ->setParameters(['venue' => $venue, 'locale' => $locale, 'slug' => $newSlug])
            ->execute();
        if (null !== $oldSlug) {
            $this->em->persist(new VenueSlugHistory($venue, $locale, $oldSlug));
        }
    }
}
