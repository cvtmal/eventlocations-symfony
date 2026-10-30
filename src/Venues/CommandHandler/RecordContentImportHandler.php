<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\PurgeListingsCache;
use App\Venues\Command\RecordContentImport;
use App\Venues\Entity\ContentImport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Records one import run in content_imports. A run that changed venues (created, updated or
 * unpublished) purges the listings from the HTTP cache once, after its record is committed
 * (PurgeListingsCache); each changed venue's own pages were purged by its handler.
 */
final class RecordContentImportHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(RecordContentImport $command): string
    {
        $import = new ContentImport(
            $command->sourcePath,
            $command->onlyKey,
            $command->pruned,
            $command->forced,
            $command->venuesSeen,
            $command->venuesCreated,
            $command->venuesUpdated,
            $command->venuesUnchanged,
            $command->venuesFailed,
            $command->venuesUnpublished,
            $command->photosAdded,
            $command->photosRemoved,
            $command->documentsAdded,
            $command->documentsRemoved,
            $command->errors,
            $command->startedAt,
            $command->finishedAt,
        );
        $this->em->persist($import);
        if ($command->venuesCreated + $command->venuesUpdated + $command->venuesUnpublished > 0) {
            $this->commandBus->dispatch(new PurgeListingsCache(), [new DispatchAfterCurrentBusStamp()]);
        }

        return $import->id();
    }
}
