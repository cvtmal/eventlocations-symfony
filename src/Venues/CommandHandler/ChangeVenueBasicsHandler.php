<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use App\Venues\Command\ChangeVenueBasics;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\Repository\VenueRepository;
use App\Venues\SlugAllocator;
use App\Venues\VenueEditing;
use App\Venues\VenueSlugs;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A rename gives every locale a new slug from the name, with history (as the import does),
 * except the default locale when a slug was typed by hand: that one goes through
 * VenueSlugs::change(). Two saves racing for one slug both pass isTaken(); the unique index
 * decides and the loser gets the same rule violation (validation.md, layer 3).
 */
final class ChangeVenueBasicsHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRepository $venues,
        private readonly VenueSlugs $slugs,
        private readonly EnabledLocale $locales,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ChangeVenueBasics $command): void
    {
        $venue = $this->editing->open($command->venueNumber, $command->loadedAt);
        $rows = $this->venues->translationsByLocale($venue);
        $default = $this->locales->default();

        if ($venue->name() !== $command->name) {
            if ('' === SlugAllocator::base($command->name)) {
                throw VenueRuleViolation::slugWithoutLetters();
            }
            $venue->rename($command->name);
            foreach ($rows as $locale => $row) {
                if ($locale === $default && null !== $command->newSlug) {
                    continue;
                }
                $slug = $this->slugs->assign($venue, $locale, $command->name, $venue->town(), $row, true);
                if ($slug !== $row->slug()) {
                    $row->changeSlug($slug);
                }
            }
        }
        if (null !== $command->newSlug && isset($rows[$default])) {
            $this->slugs->change($venue, $rows[$default], $command->newSlug);
        }
        $venue->changeCapacity($command->capacityMax, $command->capacityMin);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $violation) {
            if (str_contains($violation->getMessage(), 'uk_venue_translations_locale_slug')) {
                throw VenueRuleViolation::slugTaken();
            }

            throw $violation;
        }

        $this->editing->close($venue);
    }
}
