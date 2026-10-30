<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RateVenue;
use App\Venues\Entity\VenueRatingHighlight;
use App\Venues\VenueEditing;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Score, count and highlights. The highlights are replaced as the import does (DQL delete,
 * then one row per position 1..n); loaded rows are detached first, because the new rows
 * share their composite id (venue, position).
 */
final class RateVenueHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RateVenue $command): void
    {
        $venue = $this->editing->open($command->venueNumber, $command->loadedAt);

        $venue->rate($command->score, $command->count);

        foreach ($this->em->getUnitOfWork()->getIdentityMap()[VenueRatingHighlight::class] ?? [] as $row) {
            if ($row instanceof VenueRatingHighlight && $row->venue() === $venue) {
                $this->em->detach($row);
            }
        }
        $this->em->createQuery(\sprintf('DELETE FROM %s h WHERE h.venue = :venue', VenueRatingHighlight::class))
            ->setParameter('venue', $venue)
            ->execute();
        $highlights = [];
        foreach ($command->highlights as $highlight) {
            $highlights[$highlight->value] = $highlight;
        }
        foreach (array_values($highlights) as $index => $highlight) {
            $this->em->persist(new VenueRatingHighlight($venue, $index + 1, $highlight));
        }

        $this->editing->close($venue);
    }
}
