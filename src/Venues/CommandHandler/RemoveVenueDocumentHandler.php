<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RemoveVenueDocument;
use App\Venues\Command\RemoveVenueFiles;
use App\Venues\Positions;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * #25, CMS-7, PF1: the row goes (its titles cascade), the documents close their gap, and after
 * the commit RemoveVenueFiles deletes the public PDF and the original, the original only when
 * no photo or document row of any venue uses it. Only the venue is purged (D19).
 */
final class RemoveVenueDocumentHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly Positions $positions,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(RemoveVenueDocument $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        $document = $this->rows->document($venue, $command->documentId);
        $documentId = $document->id();
        $path = $document->path();

        $this->em->remove($document);
        $this->em->flush();
        $this->positions->renumber($this->rows->documents($venue));

        $this->commandBus->dispatch(
            new RemoveVenueFiles($command->venueNumber, [], [$documentId], $this->rows->isOriginalUsed($path) ? [] : [$path]),
            [new DispatchAfterCurrentBusStamp()],
        );
        $this->editing->close($venue, purgeListings: false);
    }
}
