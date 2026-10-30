<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\PurgeVenueCache;
use App\Venues\Command\UnpublishMissingContent;
use App\Venues\Repository\VenueRepository;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Unpublishes every content venue whose folder is gone and forgets its content hash, so a
 * folder that comes back is applied (and published) again, and purges its pages from the
 * HTTP cache after the commit (PurgeVenueCache). Files and rows are kept.
 *
 * @return int the number of venues unpublished (or that a dry run would unpublish)
 */
final class UnpublishMissingContentHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueRepository $venues,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(UnpublishMissingContent $command): int
    {
        $missing = $this->venues->contentVenuesMissingFrom($command->presentKeys);
        if (!$command->dryRun) {
            foreach ($missing as $venue) {
                $venue->unpublish();
                $venue->forgetContentHash();
                $this->commandBus->dispatch(new PurgeVenueCache($venue->number()), [new DispatchAfterCurrentBusStamp()]);
            }
        }

        return \count($missing);
    }
}
