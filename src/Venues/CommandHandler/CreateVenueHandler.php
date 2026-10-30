<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use App\Venues\Command\CreateVenue;
use App\Venues\Command\RecordVenueCreated;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueTranslation;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\SlugAllocator;
use App\Venues\ValueObject\VenueStatus;
use App\Venues\VenueSlugs;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Creates an unpublished venue without an address, with a default-locale translation whose
 * slug comes from the name and whose summary is still empty (publishing needs one). It is a
 * CMS venue from the start (markCmsEdited()). No purge: an unpublished venue is on no public
 * page.
 *
 * The audit row needs the number, which the database assigns at the insert, so the handler
 * flushes and then dispatches RecordVenueCreated without a stamp: it runs inside this
 * transaction, and a rollback takes the venue and its audit row together.
 */
final class CreateVenueHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueSlugs $slugs,
        private readonly EnabledLocale $locales,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return int the new venue's number
     */
    public function __invoke(CreateVenue $command): int
    {
        if ('' === SlugAllocator::base($command->name)) {
            throw VenueRuleViolation::slugWithoutLetters();
        }
        $venue = new Venue($command->name, $command->tier, VenueStatus::Unpublished, $command->capacityMax, null, null, null, null, null, null);
        $this->em->persist($venue);

        $default = $this->locales->default();
        $slug = $this->slugs->assign($venue, $default, $command->name, null, null, false);
        $this->em->persist(new VenueTranslation($venue, $default, $slug, ''));
        $venue->markCmsEdited($this->clock->now());
        $this->em->flush();

        $this->commandBus->dispatch(new RecordVenueCreated($venue->number(), $venue->name()));

        return $venue->number();
    }
}
