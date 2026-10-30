<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueTerm;
use App\Venues\Repository\TaxonomyTermRepository;
use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Replaces a venue's terms group by group (#24, D16). Only the listed groups change, so the
 * CMS cards that own different groups (Merkmale, Hochzeit) never wipe each other.
 *
 * VenueTerm has no mutator, so a change is a delete and an insert, in the order the import
 * uses (DQL DELETE now, the inserts at the caller's flush). The caller checks the codes and
 * that a main term is among them (a form rule); a violation here is a programming error.
 */
final class VenueTermSet
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaxonomyTermRepository $terms,
    ) {
    }

    /**
     * @param array<string, list<string>> $codesByGroup TermGroup value → the selected codes
     * @param array<string, string|null>  $mainByGroup  TermGroup value → the main code, null for none.
     *                                                  A listed group without an entry keeps its main
     *                                                  term while that term stays selected (the CMS has
     *                                                  no main control for `suitable_for`, the format does).
     */
    public function replace(Venue $venue, array $codesByGroup, array $mainByGroup): void
    {
        if ([] === $codesByGroup) {
            return;
        }
        $groups = array_map(TermGroup::from(...), array_keys($codesByGroup));
        $mains = $mainByGroup + $this->currentMains($venue, $groups);

        $this->forgetManaged($venue, $groups);
        $this->em->createQuery(\sprintf('DELETE FROM %s x WHERE x.venue = :venue AND x.termGroup IN (:groups)', VenueTerm::class))
            ->setParameter('venue', $venue)
            ->setParameter('groups', array_map(static fn (TermGroup $group): string => $group->value, $groups))
            ->execute();

        foreach ($groups as $group) {
            $codes = $codesByGroup[$group->value];
            $main = $mains[$group->value] ?? null;
            if (null !== $main && !\in_array($main, $codes, true)) {
                if (\array_key_exists($group->value, $mainByGroup)) {
                    throw new \InvalidArgumentException(\sprintf('The main %s term "%s" is not selected.', $group->value, $main));
                }
                $main = null; // the kept main term was deselected
            }
            $byCode = [];
            foreach ($this->terms->byGroup($group) as $term) {
                $byCode[$term->code()] = $term;
            }
            foreach (array_unique($codes) as $code) {
                $term = $byCode[$code] ?? throw new \InvalidArgumentException(\sprintf('Unknown %s term "%s".', $group->value, $code));
                $this->em->persist(new VenueTerm($venue, $term, $main === $code));
            }
        }
    }

    /**
     * @param list<TermGroup> $groups
     *
     * @return array<string, string> TermGroup value → the current main code
     */
    private function currentMains(Venue $venue, array $groups): array
    {
        /** @var array<string, string> $mains */
        $mains = $this->em->getConnection()->fetchAllKeyValue(
            'SELECT term_group, term_code FROM venue_terms WHERE venue_id = :venue AND is_main AND term_group IN (:groups)',
            ['venue' => $venue->id(), 'groups' => array_map(static fn (TermGroup $group): string => $group->value, $groups)],
            ['groups' => ArrayParameterType::STRING],
        );

        return $mains;
    }

    /**
     * Detaches loaded rows of the replaced groups, so the new rows (same composite id) do not
     * collide with them in the identity map.
     *
     * @param list<TermGroup> $groups
     */
    private function forgetManaged(Venue $venue, array $groups): void
    {
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[VenueTerm::class] ?? [] as $row) {
            if ($row instanceof VenueTerm && $row->venue() === $venue && \in_array($row->termGroup(), $groups, true)) {
                $this->em->detach($row);
            }
        }
    }
}
