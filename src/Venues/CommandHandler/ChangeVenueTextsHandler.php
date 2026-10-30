<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use App\Venues\Command\ChangeVenueTexts;
use App\Venues\Entity\VenueTranslation;
use App\Venues\Repository\VenueRepository;
use App\Venues\VenueEditing;
use App\Venues\VenueSlugs;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Revises each locale's texts and keeps its slug. A locale without a row gets one (with a
 * slug from the name) once it has a summary; a blank one stays without, so an untouched
 * second language adds nothing. Locales the site does not serve are ignored.
 */
final class ChangeVenueTextsHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRepository $venues,
        private readonly VenueSlugs $slugs,
        private readonly EnabledLocale $locales,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ChangeVenueTexts $command): void
    {
        $venue = $this->editing->open($command->venueNumber, $command->loadedAt);
        $rows = $this->venues->translationsByLocale($venue);

        foreach ($command->texts as $locale => $text) {
            if (!$this->locales->isEnabled($locale)) {
                continue;
            }
            $row = $rows[$locale] ?? null;
            if (null !== $row) {
                $row->revise($row->slug(), $text['summary'], $text['description'], $text['metaDescription'], $text['parkingText']);
                continue;
            }
            if ('' === trim($text['summary'])) {
                continue;
            }
            $slug = $this->slugs->assign($venue, $locale, $venue->name(), $venue->town(), null, false);
            $this->em->persist(new VenueTranslation($venue, $locale, $slug, $text['summary'], $text['description'], $text['metaDescription'], $text['parkingText']));
        }

        $this->editing->close($venue);
    }
}
